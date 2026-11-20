<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\PublicHttpClient;

/** Search traffic and read-only webmaster diagnostics. All destinations are fixed vendor APIs. */
final readonly class SearchReports implements ReportProvider
{
    private const METRICS = ['clicks','impressions','ctr','position'];
    private const INDICATORS = ['TOTAL_CLICKS','TOTAL_SHOWS','AVG_SHOW_POSITION'];
    public function __construct(private string $provider, private OutboundHttpTransport $http = new PublicHttpClient())
    {
        if (!in_array($provider, ['webmaster','search_console'], true)) throw new \InvalidArgumentException('Unknown search provider');
    }
    public function id(): string { return $this->provider; }
    public function validateCredential(#[\SensitiveParameter] array $credential): void
    {
        (new ApiReports($this->provider === 'webmaster' ? 'metrika' : 'ga4', $this->http))->validateCredential($credential);
    }
    public static function validResource(string $provider, string $resource): bool
    {
        if (strlen($resource)>512 || preg_match('/[\x00-\x20\x7f\\\\]/', $resource)) return false;
        $dns='(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,62}';
        if ($provider==='webmaster') return preg_match('~^https?:'.$dns.':(?:80|443)$~D',$resource)===1 && (str_starts_with($resource,'https:') ? str_ends_with($resource,':443') : str_ends_with($resource,':80'));
        if ($provider!=='search_console') return false;
        if (str_starts_with($resource,'sc-domain:')) return preg_match('~^sc-domain:'.$dns.'$~D',$resource)===1;
        $p=parse_url($resource);
        return is_array($p) && in_array($p['scheme']??'', ['http','https'],true) && preg_match('~^'.$dns.'$~D',$p['host']??'')===1 && !isset($p['user'],$p['pass']) && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']) && str_ends_with($resource,'/') && (!isset($p['port']) || $p['port']===($p['scheme']==='https'?443:80));
    }
    private function json(string $url,string $method, #[\SensitiveParameter] array $headers, array $body=[]): array
    {
        try { [$status,$raw]=$this->http->request($url,$method,$headers,$body===[]?'':json_encode($body,JSON_THROW_ON_ERROR),2097152); }
        catch(\Throwable) { throw new \RuntimeException('Analytics transport unavailable'); }
        if($status<200||$status>=300) throw new \RuntimeException('Analytics provider HTTP '.$status);
        if(trim($raw)===''&&$method==='PUT') return [];
        try {$r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Invalid analytics response');}
        if(!is_array($r)||isset($r['error'])||isset($r['error_code']))throw new \RuntimeException('Invalid analytics response');
        return $r;
    }
    private function session(string $resource, #[\SensitiveParameter] array $credential, bool $write=false): array
    {
        if(!self::validResource($this->provider,$resource))throw new \InvalidArgumentException('Invalid search resource');
        $this->validateCredential($credential);
        if($this->provider==='webmaster'){
            $headers=['Authorization'=>'OAuth '.$credential['token'],'Content-Type'=>'application/json'];
            $user=$this->json('https://api.webmaster.yandex.net/v4/user','GET',$headers)['user_id']??null;
            if(!(is_string($user)||is_int($user))||!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$user))throw new \RuntimeException('Invalid analytics response');
            return ['https://api.webmaster.yandex.net/v4/user/'.$user.'/hosts/'.rawurlencode($resource),$headers];
        }
        $token=(new ApiReports('ga4',$this->http))->accessToken($credential,$write?'webmasters':'webmasters.readonly');
        return ['https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($resource),['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json']];
    }
    private static function num(mixed $v): ?float
    {
        if($v===null)return null;
        if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new \RuntimeException('Invalid analytics response');
        return (float)$v;
    }
    private static function label(mixed $v,bool $url=false): string
    {
        if(!is_string($v))throw new \RuntimeException('Invalid analytics response');
        if($url){$v=preg_split('/[?#]/',$v,2)[0];$v=preg_replace('~^(https?://)[^/@]+@~i','$1',$v);}
        return substr($v,0,2048);
    }
    private static function section(array $rows,?string $dimension,bool $truncated=false,array $quality=[]): array
    {return ['rows'=>$rows,'dimension'=>$dimension,'truncated'=>$truncated,'quality'=>$quality?:['source'=>'provider']];}
    private static function values(array $a): array
    {
        $click=self::num($a['TOTAL_CLICKS']??null);$show=self::num($a['TOTAL_SHOWS']??null);
        return [$click,$show,$show>0&&$click!==null?$click/$show:null,self::num($a['AVG_SHOW_POSITION']??null)];
    }
    private static function aggregate(array $rows): array
    {
        if(!$rows)return [];
        $c=0.;$s=0.;$p=0.;$completeC=true;$completeS=true;$completeP=true;
        foreach($rows as $r){[$click,$show,,$pos]=$r['values'];if($click===null)$completeC=false;else $c+=$click;if($show===null)$completeS=false;else $s+=$show;if($show===null||($show>0&&$pos===null))$completeP=false;else $p+=($pos??0)*$show;}
        return [['label'=>'Total (available days)','values'=>[$completeC?$c:null,$completeS?$s:null,$completeC&&$completeS&&$s>0?$c/$s:null,$completeP&&$s>0?$p/$s:null]]];
    }
    public function report(string $resource, #[\SensitiveParameter] array $credential,string $from,string $to,?string $goal): array
    {
        AnalyticsHub::period($from,$to);if($goal!==null)throw new \InvalidArgumentException('Search reports do not support goals');
        [$base,$headers]=$this->session($resource,$credential);$sections=[];$meta=[];
        if($this->provider==='search_console'){
            foreach(['overview'=>null,'daily'=>'date','queries'=>'query','pages'=>'page','devices'=>'device','countries'=>'country'] as $section=>$dim){
                $limit=$dim===null?1:100;$body=['startDate'=>$from,'endDate'=>$to,'type'=>'web','dataState'=>'final','rowLimit'=>$limit];if($dim!==null)$body['dimensions']=[$dim];
                $r=$this->json($base.'/searchAnalytics/query','POST',$headers,$body);$rows=[];
                if(isset($r['rows'])&&!is_array($r['rows']))throw new \RuntimeException('Invalid analytics response');
                foreach($r['rows']??[] as $row){$v=[];foreach(self::METRICS as $m){if(!array_key_exists($m,$row))throw new \RuntimeException('Invalid analytics response');$v[]=self::num($row[$m]);}$rows[]=['label'=>$dim===null?'Total':self::label($row['keys'][0]??null,$dim==='page'),'values'=>$v];}
                if(count($rows)>$limit)throw new \RuntimeException('Invalid analytics response');
                if($dim==='date')usort($rows,fn($a,$b)=>strcmp($a['label'],$b['label']));
                $sections[$section]=self::section($rows,$dim,$dim!==null&&count($rows)>=$limit,['limited_by_provider'=>true,'aggregation'=>$r['responseAggregationType']??null]);
            }
            $r=$this->json($base.'/sitemaps','GET',$headers);$maps=[];
            foreach(array_slice($r['sitemap']??[],0,100) as $m)$maps[]=['url'=>self::label($m['path']??null,true),'status'=>!empty($m['isPending'])?'pending':'processed','errors'=>self::num($m['errors']??null),'warnings'=>self::num($m['warnings']??null),'last_read'=>$m['lastDownloaded']??null];
            $meta=['sitemaps'=>$maps,'sitemaps_limited'=>count($r['sitemap']??[])>100];
        }else{
            $params='date_from='.rawurlencode($from.'T00:00:00+03:00').'&date_to='.rawurlencode($to.'T23:59:59+03:00').'&device_type_indicator=ALL';foreach(self::INDICATORS as $i)$params.='&query_indicator='.$i;
            $r=$this->json($base.'/search-queries/all/history?'.$params,'GET',$headers);$days=[];
            if(!isset($r['indicators'])||!is_array($r['indicators']))throw new \RuntimeException('Invalid analytics response');
            foreach(self::INDICATORS as $i)foreach($r['indicators'][$i]??[] as $entry){$d=substr((string)($entry['date']??''),0,10);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)||$d<$from||$d>$to)continue;$days[$d][$i]=$entry['value']??null;}
            ksort($days);$rows=[];foreach($days as $d=>$v)$rows[]=['label'=>$d,'values'=>self::values($v)];
            $sections['overview']=self::section(self::aggregate($rows),null,false,['available_days'=>count($rows)]);$sections['daily']=self::section($rows,'date');
            $r=$this->json($base.'/search-queries/popular?'.$params.'&order_by=TOTAL_CLICKS&limit=100','GET',$headers);$rows=[];
            if(!isset($r['queries'])||!is_array($r['queries']))throw new \RuntimeException('Invalid analytics response');
            foreach($r['queries'] as $q)$rows[]=['label'=>self::label($q['query_text']??null),'values'=>self::values($q['indicators']??[])];
            $sections['queries']=self::section($rows,'query',(int)($r['count']??count($rows))>count($rows),['actual_from'=>$r['date_from']??null,'actual_to'=>$r['date_to']??null]);
            // URL monitoring only exposes recent two-week data; never label it as a historical full-period report.
            $recentFrom=max($from,gmdate('Y-m-d',time()-14*86400));
            if($recentFrom<=$to){$r=$this->json($base.'/query-analytics/list','POST',$headers,['offset'=>0,'limit'=>100,'device_type_indicator'=>'ALL','search_location'=>'WEB_LOCATION','text_indicator'=>'URL']);$rows=[];
                if(!is_array($r['text_indicator_to_statistics']??null))throw new \RuntimeException('Invalid analytics response');
                foreach($r['text_indicator_to_statistics'] as $entry){$byDay=[];foreach($entry['statistics']??[] as $stat){$d=$stat['date']??'';$key=match($stat['field']??''){'CLICKS'=>'TOTAL_CLICKS','IMPRESSIONS'=>'TOTAL_SHOWS','POSITION'=>'AVG_SHOW_POSITION',default=>null};if($key!==null&&$d>=$recentFrom&&$d<=$to)$byDay[$d][$key]=$stat['value']??null;}$tmp=[];foreach($byDay as $v)$tmp[]=['values'=>self::values($v)];$agg=self::aggregate($tmp);if($agg)$rows[]=['label'=>self::label($entry['text_indicator']['value']??null,true),'values'=>$agg[0]['values']];}
                usort($rows,fn($a,$b)=>($b['values'][0]??-1)<=>($a['values'][0]??-1));$sections['pages']=self::section($rows,'page',(int)($r['count']??0)>100,['actual_from'=>$recentFrom,'actual_to'=>$to,'limited_by_provider'=>true]);
            }
            $r=$this->json($base.'/sitemaps?limit=100','GET',$headers);if(!is_array($r['sitemaps']??null))throw new \RuntimeException('Invalid analytics response');$maps=[];foreach($r['sitemaps'] as $m)$maps[]=['url'=>self::label($m['sitemap_url']??null,true),'status'=>$m['sitemap_type']??null,'errors'=>self::num($m['errors_count']??null),'urls'=>self::num($m['urls_count']??null),'last_read'=>$m['last_access_date']??null];
            $r=$this->json($base.'/indexing/samples?limit=100','GET',$headers);if(!is_array($r['samples']??null))throw new \RuntimeException('Invalid analytics response');$samples=[];foreach($r['samples'] as $m)$samples[]=['url'=>self::label($m['url']??null,true),'status'=>$m['status']??null,'http_code'=>$m['http_code']??null,'last_read'=>$m['access_date']??null];
            $meta=['sitemaps'=>$maps,'sitemaps_limited'=>count($maps)>=100,'index_samples'=>$samples,'index_sample_count'=>$r['count']??null];
        }
        return ['provider'=>$this->provider,'resource'=>$resource,'from'=>$from,'to'=>$to,'metrics'=>self::METRICS,'labels'=>['Clicks','Impressions','CTR (ratio)','Average position'],'timezone'=>$this->provider==='webmaster'?'Europe/Moscow':'America/Los_Angeles','sections'=>$sections,'webmaster'=>$meta,'fetched_at'=>gmdate(DATE_ATOM)];
    }
    public function action(string $resource, #[\SensitiveParameter] array $credential,string $action,string $url): array
    {
        // Only permit URLs within the configured property; no direct HTTP requests to user URLs.
        if(strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||!filter_var($url,FILTER_VALIDATE_URL))throw new \InvalidArgumentException('Invalid site URL');
        $p=parse_url($url);if(!in_array($p['scheme']??'', ['http','https'],true)||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))throw new \InvalidArgumentException('Invalid site URL');
        if(!self::validResource($this->provider,$resource))throw new \InvalidArgumentException('Invalid search resource');
        if(!in_array($action,['submit_sitemap','inspect'],true)||($action==='inspect'&&$this->provider!=='search_console'))throw new \InvalidArgumentException('Unsupported search action');
        if($this->provider==='webmaster'){$parts=explode(':',$resource);$ok=$p['scheme']===$parts[0]&&strtolower($p['host'])===strtolower($parts[1]??'')&&($p['port']??($p['scheme']==='https'?443:80))===(int)($parts[2]??0);}
        elseif(str_starts_with($resource,'sc-domain:')){$domain=strtolower(substr($resource,10));$host=strtolower($p['host']);$ok=$host===$domain||str_ends_with($host,'.'.$domain);}
        else $ok=str_starts_with($url,$resource)&&!preg_match('~(?:^|/)(?:\.{1,2}|%2e(?:%2e)?)(?:/|$)~i',$p['path']??'');
        if(!$ok)throw new \InvalidArgumentException('URL outside configured property');
        [$base,$headers]=$this->session($resource,$credential,$action==='submit_sitemap');
        if($action==='submit_sitemap'){
            if($this->provider==='webmaster')$this->json($base.'/user-added-sitemaps','POST',$headers,['url'=>$url]);else $this->json($base.'/sitemaps/'.rawurlencode($url),'PUT',$headers);
            return ['accepted'=>true];
        }
        {
            $r=$this->json('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect','POST',$headers,['inspectionUrl'=>$url,'siteUrl'=>$resource,'languageCode'=>'en-US']);$i=$r['inspectionResult']['indexStatusResult']??null;if(!is_array($i))throw new \RuntimeException('Invalid analytics response');
            $i=array_intersect_key($i,array_flip(['verdict','coverageState','robotsTxtState','indexingState','lastCrawlTime','pageFetchState','googleCanonical','userCanonical']));foreach(['googleCanonical','userCanonical'] as $k)if(isset($i[$k]))$i[$k]=self::label($i[$k],true);return $i;
        }
    }
}
