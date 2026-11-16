<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\PublicHttpClient;

/** HTTPS-only endpoints, fixed API methods and bounded normalized reports. */
final readonly class AlternativeReports implements ReportProvider
{
    public function __construct(private string $provider, private OutboundHttpTransport $http = new PublicHttpClient()) {
        if(!in_array($provider,['matomo','plausible'],true))throw new \InvalidArgumentException('Unknown alternative provider');
    }
    public function id(): string {return $this->provider;}
    public static function domain(string $value): bool {
        return strlen($value)<=253 && preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$~D',$value)===1;
    }
    public static function base(string $value): string {
        if(strlen($value)>512 || !preg_match('~^https://([a-z0-9.-]+)((?:/[a-zA-Z0-9_-]+)*)/?$~D',$value,$m) || !self::domain($m[1]))throw new \InvalidArgumentException('Invalid HTTPS analytics endpoint');
        return rtrim($value,'/');
    }
    public static function validResource(string $provider,string $resource): bool {
        return $provider==='matomo'?preg_match('/^[1-9][0-9]{0,19}$/D',$resource)===1:self::domain($resource);
    }
    public function validateCredential(#[\SensitiveParameter] array $c): void {
        if(array_diff(array_keys($c),['type','token','endpoint'])||($c['type']??null)!=='api_token'||!is_string($c['token']??null)||!preg_match('/^[\x21-\x7e]{1,1024}$/D',$c['token'])||!is_string($c['endpoint']??null))throw new \InvalidArgumentException('Invalid alternative analytics credential');
        self::base($c['endpoint']);
    }
    private function json(string $url,array $headers,#[\SensitiveParameter] string $body): array {
        try{[$status,$raw]=$this->http->request($url,'POST',$headers,$body,2097152);}catch(\Throwable){throw new \RuntimeException('Analytics transport unavailable');}
        if($status<200||$status>=300)throw new \RuntimeException('Analytics provider HTTP '.$status);
        try{$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Invalid analytics response');}
        if(!is_array($r)||isset($r['error'])||($r['result']??null)==='error')throw new \RuntimeException('Invalid analytics response');return $r;
    }
    private static function number(mixed $v): float {if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new \RuntimeException('Invalid analytics response');return (float)$v;}
    private static function row(string $label,array $values): array {return ['label'=>substr($label,0,2048),'values'=>array_map(static fn($v)=>$v===null?null:self::number($v),$values)];}
    private static function section(array $rows,?string $dimension,bool $truncated=false): array {return ['rows'=>$rows,'dimension'=>$dimension,'truncated'=>$truncated,'quality'=>['limited_by_provider'=>false]];}
    private static function page(string $label): string {return preg_replace('~^(https?://)[^/@]+@~i','$1',preg_split('/[?#]/',$label,2)[0]);}
    public function report(string $resource,#[\SensitiveParameter] array $credential,string $from,string $to,?string $goal): array {
        $this->validateCredential($credential);if(!self::validResource($this->provider,$resource)||$goal!==null)throw new \InvalidArgumentException('Invalid alternative analytics resource');
        AnalyticsHub::period($from,$to);$base=self::base($credential['endpoint']);
        $result=['provider'=>$this->provider,'resource'=>$resource,'from'=>$from,'to'=>$to,'timezone'=>null,'sections'=>[]];
        if($this->provider==='plausible'){
            $metrics=['visitors','pageviews'];$result['metrics']=$metrics;$result['labels']=['Visitors','Pageviews'];
            foreach(['overview'=>null,'daily'=>'time:day','channels'=>'visit:source','pages'=>'event:page','events'=>'event:goal'] as $section=>$dim){
                $sectionMetrics=$section==='events'?['visitors','events']:$metrics;
                $limit=$dim===null?1:100;$params=['site_id'=>$resource,'metrics'=>$sectionMetrics,'date_range'=>[$from,$to],'dimensions'=>$dim===null?[]:[$dim],'pagination'=>['limit'=>$limit,'offset'=>0]];
                if($dim!==null)$params['order_by']=[$section==='daily'?[$dim,'asc']:['visitors','desc']];
                try{$r=$this->json($base.'/api/v2/query',['Authorization'=>'Bearer '.$credential['token'],'Content-Type'=>'application/json'],json_encode($params,JSON_THROW_ON_ERROR));}catch(\RuntimeException $e){if($section!=='events'||!in_array($e->getMessage(),['Analytics provider HTTP 400','Analytics provider HTTP 403','Analytics provider HTTP 404'],true))throw $e;$result['sections']['events']=self::section([],$dim)+['metrics'=>$sectionMetrics,'labels'=>['Visitors','Goal completions']];$result['sections']['events']['quality']['unavailable']=$e->getMessage();continue;}
                if(!is_array($r['results']??null)||count($r['results'])>$limit)throw new \RuntimeException('Invalid analytics response');$rows=[];
                foreach($r['results'] as $row){if(!is_array($row['metrics']??null)||count($row['metrics'])!==2||($dim!==null&&!is_string($row['dimensions'][0]??null)))throw new \RuntimeException('Invalid analytics response');$label=$dim===null?'Total':$row['dimensions'][0];$rows[]=self::row($section==='pages'?self::page($label):$label,$row['metrics']);}
                $result['sections'][$section]=self::section($rows,$dim,$dim!==null&&$section!=='daily'&&count($rows)===$limit);if($section==='events')$result['sections'][$section]+=['metrics'=>$sectionMetrics,'labels'=>['Visitors','Goal completions']];
            }
        }else{
            $result['metrics']=['visits','pageviews'];$result['labels']=['Visits','Pageviews'];
            $call=function(string $method,string $period,array $extra=[])use($base,$resource,$credential,$from,$to):array{return $this->json($base.'/index.php',['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query(['module'=>'API','method'=>$method,'idSite'=>$resource,'period'=>$period,'date'=>$from.','.$to,'format'=>'JSON','token_auth'=>$credential['token']]+$extra,'','&',PHP_QUERY_RFC3986));};
            // Page views are Actions.nb_pageviews, not VisitsSummary.nb_actions.
            $visits=$call('VisitsSummary.get','range');$actions=$call('Actions.get','range');
            $result['sections']['overview']=self::section([self::row('Total',[self::number($visits['nb_visits']??null),self::number($actions['nb_pageviews']??null)])],null);
            $visits=$call('VisitsSummary.get','day');$actions=$call('Actions.get','day');$rows=[];if($from===$to){if(isset($visits['nb_visits']))$visits=[$from=>$visits];if(isset($actions['nb_pageviews']))$actions=[$from=>$actions];}
            for($date=$from;$date<=$to;$date=gmdate('Y-m-d',strtotime($date.' UTC')+86400)){
                // Missing dates remain missing, rather than fabricated zeroes.
                if(!isset($visits[$date])&&!isset($actions[$date]))continue;
                $v=$visits[$date]??[];$a=$actions[$date]??[];
                $rows[]=self::row($date,[isset($v['nb_visits'])?self::number($v['nb_visits']):null,isset($a['nb_pageviews'])?self::number($a['nb_pageviews']):null]);
            }
            $result['sections']['daily']=self::section($rows,'date');
            foreach(['channels'=>'Referrers.getReferrerType','pages'=>'Actions.getPageUrls'] as $section=>$method){
                $data=$call($method,'range',['filter_limit'=>100,'flat'=>1]);if(!array_is_list($data)||count($data)>100)throw new \RuntimeException('Invalid analytics response');$rows=[];
                foreach($data as $row){if(!is_string($row['label']??null))throw new \RuntimeException('Invalid analytics response');$label=$section==='pages'?self::page((string)($row['url']??$row['label'])):$row['label'];$rows[]=self::row($label,[self::number($row['nb_visits']??null),$section==='pages'?self::number($row['nb_hits']??null):null]);}
                $result['sections'][$section]=self::section($rows,$method,count($rows)===100);
            }
            $data=$call('Events.getAction','range',['filter_limit'=>100,'flat'=>1]);if(!array_is_list($data)||count($data)>100)throw new \RuntimeException('Invalid analytics response');$rows=[];
            foreach($data as $row){if(!is_string($row['label']??null))throw new \RuntimeException('Invalid analytics response');$rows[]=self::row($row['label'],[self::number($row['nb_visits']??null),self::number($row['nb_events']??null)]);}
            $result['sections']['events']=self::section($rows,'Events.getAction',count($rows)===100)+['metrics'=>['visits','events'],'labels'=>['Visits with event','Events']];
        }
        return $result+['fetched_at'=>gmdate(DATE_ATOM)];
    }
}
