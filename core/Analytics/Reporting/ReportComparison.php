<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

/** Compare equal-length, non-overlapping snapshots of one resource, not sums of daily users. */
final class ReportComparison
{
    public static function previous(string $from,string $to): ?array
    {
        AnalyticsHub::period($from,$to);
        $start=new \DateTimeImmutable($from,new \DateTimeZone('UTC'));
        $end=new \DateTimeImmutable($to,new \DateTimeZone('UTC'));
        $days=(int)$start->diff($end)->days+1;
        $previous=$start->modify('-'.$days.' days')->format('Y-m-d');
        return $previous<'2000-01-01'?null:[$previous,$start->modify('-1 day')->format('Y-m-d')];
    }
    private static function complete(array $report): bool
    {
        $section=$report['sections']['overview']??[];$q=$section['quality']??[];
        if(!empty($q['unavailable'])||!empty($section['truncated'])||!empty($q['thresholded'])||!empty($q['data_loss']))return false;
        if(isset($q['available_days'])){
            $days=(int)(new \DateTimeImmutable($report['from']))->diff(new \DateTimeImmutable($report['to']))->days+1;
            if((int)$q['available_days']!==$days)return false;
        }
        return count($section['rows']??[])===1;
    }
    public static function compare(array $current,?array $previous,string $from,string $to): array
    {
        $result=['from'=>$from,'to'=>$to,'status'=>'missing','fetched_at'=>null,'sampled'=>false,'metrics'=>[]];
        if($previous===null)return $result;
        $result['fetched_at']=$previous['fetched_at']??null;
        $result['status']='incompatible';
        if(($previous['from']??null)!==$from||($previous['to']??null)!==$to||self::previous($current['from'],$current['to'])!==[$from,$to])return $result;
        foreach(['provider','resource','timezone','metrics','labels'] as $field)if(($current[$field]??null)!==($previous[$field]??null))return $result;
        if(!self::complete($current)||!self::complete($previous)){$result['status']='incomplete';return $result;}
        // Today's partial day is not comparable to a completed historical day.
        $zone=$current['timezone']??'UTC';try{$tz=new \DateTimeZone($zone);}catch(\Throwable){$tz=new \DateTimeZone('UTC');}
        if($current['to']>=(new \DateTimeImmutable('now',$tz))->format('Y-m-d')){$result['status']='incomplete';return $result;}
        $a=$current['sections']['overview']['rows'][0]['values']??[];$b=$previous['sections']['overview']['rows'][0]['values']??[];
        $metrics=$current['metrics'];if(count($a)!==count($metrics)||count($b)!==count($metrics))return $result;
        $result['status']='ready';$result['sampled']=!empty($current['sections']['overview']['quality']['sampled'])||!empty($previous['sections']['overview']['quality']['sampled']);
        foreach($metrics as $i=>$metric){
            $value=is_numeric($a[$i])&&is_finite((float)$a[$i])?(float)$a[$i]:null;
            $base=is_numeric($b[$i])&&is_finite((float)$b[$i])?(float)$b[$i]:null;
            $delta=$value!==null&&$base!==null?$value-$base:null;
            $percent=$delta!==null&&$base!==0.0?100*$delta/$base:null;
            $result['metrics'][]=['metric'=>$metric,'label'=>$current['labels'][$i],'current'=>$value,'previous'=>$base,'delta'=>$delta,'percent'=>$percent!==null&&is_finite($percent)?$percent:null];
        }
        return $result;
    }
}
