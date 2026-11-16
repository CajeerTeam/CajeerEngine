<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\PublicHttpClient;

/** Read-only, fixed GraphQL queries. Edge and browser metrics are separate datasets. */
final readonly class CloudflareReports implements ReportProvider
{
    public function __construct(private string $provider, private OutboundHttpTransport $http = new PublicHttpClient()) {
        if(!in_array($provider,['cf_zone','cf_web'],true))throw new \InvalidArgumentException('Unknown Cloudflare provider');
    }
    public function id(): string {return $this->provider;}
    public static function validResource(string $resource): bool {return preg_match('/^[a-f0-9]{32}$/D',$resource)===1;}
    public function validateCredential(#[\SensitiveParameter] array $c): void {
        $web=$this->provider==='cf_web';
        if(array_diff(array_keys($c),$web?['type','token','account_id']:['type','token'])||($c['type']??null)!=='api_token'||!is_string($c['token']??null)||!preg_match('/^[A-Za-z0-9_-]{1,256}$/D',$c['token'])||($web&&(!is_string($c['account_id']??null)||!self::validResource($c['account_id']))))throw new \InvalidArgumentException('Invalid Cloudflare credential');
    }
    private function fetch(string $query,array $vars,#[\SensitiveParameter] string $token,string $scope,int $limit): array {
        try{[$status,$body]=$this->http->request('https://api.cloudflare.com/client/v4/graphql','POST',['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],json_encode(['query'=>$query,'variables'=>$vars],JSON_THROW_ON_ERROR),2097152);}catch(\Throwable){throw new \RuntimeException('Analytics transport unavailable');}
        if($status<200||$status>=300)throw new \RuntimeException('Analytics provider HTTP '.$status);
        try{$data=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Invalid analytics response');}
        if(!is_array($data))throw new \RuntimeException('Invalid analytics response');
        // HTTP 200 is not success if GraphQL returned errors. Never expose provider messages (may contain sensitive data).
        if(!empty($data['errors'])){
            $unsupported=is_array($data['errors']);
            foreach(is_array($data['errors'])?$data['errors']:[] as $error){$message=is_array($error)?($error['message']??''):'';if(!is_string($message)||!preg_match('/not (?:available|enabled|supported|authorized|permitted)|permission|access denied|forbidden|cannot query field|unknown (?:field|argument)|does not have access|not allowed|limit of.*days|time range|date range/i',$message))$unsupported=false;}
            throw new \RuntimeException($unsupported?'Cloudflare dataset unavailable':'Cloudflare GraphQL query rejected');
        }
        $scopes=$data['data']['viewer'][$scope]??null;
        if(!is_array($scopes)||count($scopes)!==1||!isset($scopes[0]['series'])||!is_array($scopes[0]['series'])||!array_is_list($scopes[0]['series'])||count($scopes[0]['series'])>$limit)throw new \RuntimeException('Invalid analytics response');
        return $scopes[0]['series'];
    }
    private static function number(mixed $v): float {
        if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)throw new \RuntimeException('Invalid analytics response');return (float)$v;
    }
    private static function section(array $rows,?string $dim,bool $truncated=false,array $quality=[]): array {return ['rows'=>$rows,'dimension'=>$dim,'truncated'=>$truncated,'quality'=>$quality+['limited_by_provider'=>true]];}
    public static function period(string $from,string $to): void {
        AnalyticsHub::period($from,$to);
        if(strtotime($to.' UTC')-strtotime($from.' UTC')>29*86400)throw new \InvalidArgumentException('Cloudflare report period must be 1–30 days');
    }
    public function report(string $resource,#[\SensitiveParameter] array $credential,string $from,string $to,?string $goal): array {
        $this->validateCredential($credential);if(!self::validResource($resource)||$goal!==null)throw new \InvalidArgumentException('Invalid Cloudflare resource');
        self::period($from,$to);$web=$this->provider==='cf_web';
        $scope=$web?'accounts':'zones';$tag=$web?'accountTag':'zoneTag';
        $vars=['scope'=>$web?$credential['account_id']:$resource,'start'=>$from.'T00:00:00Z','end'=>gmdate('Y-m-d\TH:i:s\Z',min(time(),strtotime($to.' UTC')+86400))];
        if($web)$vars['site']=$resource;
        $metrics=$web?['visits','pageviews']:['requests','bytes','visits'];
        $labels=$web?['Visits (Cloudflare)','Pageviews (RUM)']:['HTTP requests','Response bytes','Visits (Cloudflare)'];
        $sections=[];
        $call=function(string $dataset,?string $dim,int $limit,string $fields)use($scope,$tag,$vars,$web,$credential):array {
            $declaration='$scope: string, $start: Time, $end: Time'.($web?', $site: string':'');
            $filter='datetime_geq: $start, datetime_lt: $end'.($web?', siteTag: $site':($dataset==='httpRequestsAdaptiveGroups'?', requestSource: "eyeball"':''));
            $order=$dim==='date'?', orderBy: [date_ASC]':($dim!==null?', orderBy: [count_DESC]':'');
            $query='query CloudflareReport('.$declaration.') { viewer { '.$scope.'(filter: {'.$tag.': $scope}) { series: '.$dataset.'(limit: '.$limit.', filter: {'.$filter.'}'.$order.') { '.$fields.($dim!==null?' dimensions { '.$dim.' }':'').' } } } }';
            return $this->fetch($query,$vars,$credential['token'],$scope,$limit);
        };
        $dataset=$web?'rumPageloadEventsAdaptiveGroups':'httpRequestsAdaptiveGroups';$fields=$web?'count sum { visits } avg { sampleInterval }':'count sum { edgeResponseBytes visits } avg { sampleInterval }';
        $dimensions=$web?['overview'=>null,'daily'=>'date','pages'=>'requestPath','countries'=>'countryName','devices'=>'deviceType']:['overview'=>null,'daily'=>'date','countries'=>'clientCountryName','cache'=>'cacheStatus','status'=>'edgeResponseStatus'];
        foreach($dimensions as $section=>$dim){
            $limit=$dim===null?1:100;
            try{$data=$call($dataset,$dim,$limit,$fields);}catch(\RuntimeException $e){if(in_array($section,['overview','daily'],true)||$e->getMessage()!=='Cloudflare dataset unavailable')throw $e;$sections[$section]=self::section([],$dim,false,['unavailable'=>'Cloudflare dataset or dimension unavailable']);continue;}
            $rows=[];$sampled=false;
            foreach($data as $row){
                if(!is_array($row))throw new \RuntimeException('Invalid analytics response');
                $label=$dim===null?'Total':($row['dimensions'][$dim]??null);
                if(!is_string($label)&&!is_int($label))throw new \RuntimeException('Invalid analytics response');$label=(string)$label;
                if($section==='daily'&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$label)||$label<$from||$label>$to))throw new \RuntimeException('Invalid analytics response');
                if($section==='pages')$label=explode('?',explode('#',$label,2)[0],2)[0];
                $count=self::number($row['count']??null);$visits=self::number($row['sum']['visits']??null);
                $values=$web?[$visits,$count]:[$count,self::number($row['sum']['edgeResponseBytes']??null),$visits];
                $sampled=$sampled||self::number($row['avg']['sampleInterval']??null)>1;
                $rows[]=['label'=>substr($label,0,2048),'values'=>$values];
            }
            $sections[$section]=self::section($rows,$dim,$dim!==null&&count($rows)===$limit,['sampled'=>$sampled]);
        }
        if(!$web){
            // HIT share of the returned breakdown, not Cloudflare's cache-efficiency metric. Other cache statuses are separate.
            $cache=$sections['cache'];$status=$sections['status'];$stats=[];$total=$sections['overview']['rows'][0]['values'][0]??null;
            if($total!==null&&$total>0&&$cache['rows']!==[]&&!$cache['truncated']&&!isset($cache['quality']['unavailable'])){$hit=0.;$cacheTotal=0.;foreach($cache['rows'] as $r){$cacheTotal+=$r['values'][0];if(strtolower($r['label'])==='hit')$hit+=$r['values'][0];}$stats[]=['label'=>'Cache HIT share (%)','values'=>[$cacheTotal>0?100*$hit/$cacheTotal:null]];}
            if($status['rows']!==[]&&!$status['truncated']&&!isset($status['quality']['unavailable'])){$errors=[4=>0.,5=>0.];foreach($status['rows'] as $r){$code=(int)$r['label'];if($code>=400&&$code<600)$errors[intdiv($code,100)]+=$r['values'][0];}foreach($errors as $class=>$n)$stats[]=['label'=>'HTTP '.$class.'xx requests','values'=>[$n]];}
            // Additional datasets are optional: permissions and plan can differ from core traffic.
            foreach(['performance'=>['httpRequestsAdaptiveGroups',null,1,'avg { edgeTimeToFirstByteMs }',['ttfb_ms'],['Mean edge TTFB (ms)']], 'security'=>['firewallEventsAdaptiveGroups','action',100,'count',['events'],['Firewall events']]] as $key=>[$node,$dim,$limit,$select,$ms,$ls]){
                try{$data=$call($node,$dim,$limit,$select);$rows=[];foreach($data as $r){$label=$dim===null?'Total':($r['dimensions'][$dim]??null);if(!is_string($label))throw new \RuntimeException('Invalid analytics response');$value=$dim===null?($r['avg']['edgeTimeToFirstByteMs']??null):($r['count']??null);$rows[]=['label'=>substr($label,0,2048),'values'=>[$dim===null&&$value===null?null:self::number($value)]];}$sections[$key]=self::section($rows,$dim,$dim!==null&&count($rows)===$limit);}
                catch(\RuntimeException $e){if($e->getMessage()!=='Cloudflare dataset unavailable')throw $e;$sections[$key]=self::section([],$dim,false,['unavailable'=>'Cloudflare dataset or permission unavailable']);}
                $sections[$key]+=['metrics'=>$ms,'labels'=>$ls];
            }
            $sections['summary']=self::section($stats,'metric',false,['sampled'=>($cache['quality']['sampled']??false)||($status['quality']['sampled']??false)||($sections['overview']['quality']['sampled']??false)])+['metrics'=>['value'],'labels'=>['Value']];
        }else{
            $names=['largestContentfulPaintP75','interactionToNextPaintP75','cumulativeLayoutShiftP75'];
            try{$data=$call('rumWebVitalsEventsAdaptiveGroups',null,1,'quantiles { '.implode(' ',$names).' }');$rows=[];
                foreach($data as $row){$v=[];foreach($names as $i=>$name){$n=$row['quantiles'][$name]??null;if($n===null){$v[]=null;continue;}if(!is_numeric($n)||!is_finite((float)$n))throw new \RuntimeException('Invalid analytics response');$v[]=(float)$n<0?null:(float)$n/($i===2?1:1000);}$rows[]=['label'=>'P75','values'=>$v];}
                $sections['vitals']=self::section($rows,null);
            }catch(\RuntimeException $e){if($e->getMessage()!=='Cloudflare dataset unavailable')throw $e;$sections['vitals']=self::section([],null,false,['unavailable'=>'Cloudflare RUM vitals unavailable']);}
            $sections['vitals']+=['metrics'=>['lcp_p75_ms','inp_p75_ms','cls_p75'],'labels'=>['LCP P75 (ms)','INP P75 (ms)','CLS P75']];
        }
        return ['provider'=>$this->provider,'resource'=>$resource,'from'=>$from,'to'=>$to,'timezone'=>'UTC','metrics'=>$metrics,'labels'=>$labels,'sections'=>$sections,'fetched_at'=>gmdate(DATE_ATOM)];
    }
}
