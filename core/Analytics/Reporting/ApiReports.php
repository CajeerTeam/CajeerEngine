<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\PublicHttpClient;

/** Fixed vendor endpoints; no arbitrary URL, query or response-body exposure. */
final readonly class ApiReports implements ReportProvider
{
    public function __construct(private string $provider, private OutboundHttpTransport $http = new PublicHttpClient())
    {
        if (!in_array($provider,['metrika','ga4'],true)) throw new \InvalidArgumentException('Unknown report provider');
    }
    public function id(): string { return $this->provider; }
    public function validateCredential(#[\SensitiveParameter] array $c): void
    {
        $type=$c['type']??'';
        $fields=match($type){'oauth_token'=>['token'],'service_account'=>['client_email','private_key'],'refresh_token'=>['client_id','client_secret','refresh_token'],default=>[]};
        if (!$fields || ($this->provider==='metrika' && $type!=='oauth_token') || array_diff(array_keys($c),array_merge(['type'],$fields))) throw new \InvalidArgumentException('Invalid credential type or fields');
        foreach($fields as $f) if(!is_string($c[$f]??null)||$c[$f]===''||strlen($c[$f])>16384||($f!=='private_key'&&preg_match('/[\x00-\x20\x7f]/',$c[$f]))) throw new \InvalidArgumentException('Invalid credential field');
        if($type==='service_account'){
            if(!filter_var($c['client_email'],FILTER_VALIDATE_EMAIL)||!function_exists('openssl_pkey_get_private'))throw new \InvalidArgumentException('Invalid service account or OpenSSL unavailable');
            $key=@openssl_pkey_get_private($c['private_key']);if($key===false||openssl_pkey_get_details($key)['type']!==OPENSSL_KEYTYPE_RSA)throw new \InvalidArgumentException('RSA private key required');
        }
    }
    private function json(string $url,string $method, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body=''): array
    {
        try { [$status,$raw]=$this->http->request($url,$method,$headers,$body,2097152); } catch(\Throwable) { throw new \RuntimeException('Analytics transport unavailable'); }
        if($status<200||$status>=300)throw new \RuntimeException('Analytics provider HTTP '.$status);
        try { $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch(\Throwable){throw new \RuntimeException('Invalid analytics response');}
        if(!is_array($v)||isset($v['error'])||isset($v['errors']))throw new \RuntimeException('Invalid analytics response');
        return $v;
    }
    private static function b64(string $v): string {return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
    public function accessToken(#[\SensitiveParameter] array $c, string $scope = 'analytics.readonly'): string
    {
        $this->validateCredential($c);
        if(!in_array($scope,['analytics.readonly','webmasters.readonly','webmasters'],true))throw new \InvalidArgumentException('Invalid OAuth scope');
        if($c['type']==='oauth_token')return $c['token'];
        if($c['type']==='service_account'){
            $now=time();$jwt=self::b64('{"alg":"RS256","typ":"JWT"}').'.'.self::b64(json_encode(['iss'=>$c['client_email'],'scope'=>'https://www.googleapis.com/auth/'.$scope,'aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600],JSON_THROW_ON_ERROR));
            if(!openssl_sign($jwt,$sig,$c['private_key'],OPENSSL_ALGO_SHA256))throw new \RuntimeException('Cannot sign service account assertion');
            $params=['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt.'.'.self::b64($sig)];
        }else{$params=['grant_type'=>'refresh_token','client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'refresh_token'=>$c['refresh_token']];}
        $r=$this->json('https://oauth2.googleapis.com/token','POST',['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query($params,'','&',PHP_QUERY_RFC3986));
        $token=$r['access_token']??null;if(!is_string($token)||$token===''||preg_match('/[\x00-\x20\x7f]/',$token))throw new \RuntimeException('Invalid OAuth response');return $token;
    }
    public function report(string $resource, #[\SensitiveParameter] array $credential,string $from,string $to,?string $goal): array
    {
        if(!preg_match('/^[1-9][0-9]{0,19}$/D',$resource)||($goal!==null&&!preg_match('/^[1-9][0-9]{0,19}$/D',$goal)))throw new \InvalidArgumentException('Invalid analytics resource');
        $token=$this->accessToken($credential);$ga=$this->provider==='ga4';
        $metrics=$ga?['activeUsers','sessions','screenPageViews','keyEvents']:['ym:s:users','ym:s:visits','ym:s:pageviews'];
        if(!$ga&&$goal!==null)$metrics[]='ym:s:goal'.$goal.'reaches';
        $labels=$ga?['Active users','Sessions','Views (web + app)','Key events']:['Users','Visits','Page views'];if(!$ga&&$goal!==null)$labels[]='Goal '.$goal.' reaches';
        $dimensions=$ga?['overview'=>null,'daily'=>'date','channels'=>'sessionDefaultChannelGroup','pages'=>'landingPage']:['overview'=>null,'daily'=>'ym:s:date','channels'=>'ym:s:lastTrafficSource','pages'=>'ym:s:startURL'];
        $result=['provider'=>$this->provider,'resource'=>$resource,'from'=>$from,'to'=>$to,'metrics'=>$metrics,'labels'=>$labels,'timezone'=>null,'sections'=>[]];
        foreach($dimensions as $section=>$dim){
            $limit=$section==='daily'?100:($section==='overview'?1:100);$rows=[];$truncated=false;
            if($ga){
                $params=['dateRanges'=>[['startDate'=>$from,'endDate'=>$to]],'metrics'=>array_map(fn($m)=>['name'=>$m],$metrics),'limit'=>(string)$limit];
                if($dim!==null){$params['dimensions']=[['name'=>$dim]];$params['orderBys']=[$section==='daily'?['dimension'=>['dimensionName'=>$dim]]:['metric'=>['metricName'=>'sessions'],'desc'=>true]];}
                $r=$this->json('https://analyticsdata.googleapis.com/v1beta/properties/'.$resource.':runReport','POST',['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],json_encode($params,JSON_THROW_ON_ERROR));
                if(!isset($r['metricHeaders'])||!is_array($r['metricHeaders'])||array_column($r['metricHeaders'],'name')!==$metrics)throw new \RuntimeException('Unexpected GA4 report schema');
                if($dim!==null&&array_column($r['dimensionHeaders']??[],'name')!==[$dim])throw new \RuntimeException('Unexpected GA4 dimension');
                if(isset($r['rows'])&&!is_array($r['rows']))throw new \RuntimeException('Invalid GA4 rows');
                foreach($r['rows']??[] as $row)$rows[]=$this->row($dim===null?'Total':($row['dimensionValues'][0]['value']??null),array_column($row['metricValues']??[],'value'),count($metrics),$section);
                $truncated=(int)($r['rowCount']??count($rows))>count($rows);$result['timezone']=$r['metadata']['timeZone']??$result['timezone'];
                $quality=['thresholded'=>(bool)($r['metadata']['subjectToThresholding']??false),'data_loss'=>(bool)($r['metadata']['dataLossFromOtherRow']??false),'sampled'=>!empty($r['metadata']['samplingMetadatas'])];
            }else{
                $params=['ids'=>$resource,'date1'=>$from,'date2'=>$to,'metrics'=>implode(',',$metrics),'limit'=>$limit,'accuracy'=>'full','lang'=>'en'];
                if($dim!==null){$params['dimensions']=$dim;$params['sort']=$section==='daily'?$dim:'-ym:s:visits';}
                $r=$this->json('https://api-metrika.yandex.net/stat/v1/data?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),'GET',['Authorization'=>'OAuth '.$token]);
                if(!isset($r['data'])||!is_array($r['data']))throw new \RuntimeException('Unexpected Metrika report schema');
                if($dim===null&&isset($r['totals']))$rows[]=$this->row('Total',$r['totals'],count($metrics),$section);
                else foreach($r['data'] as $row)$rows[]=$this->row($dim===null?'Total':($row['dimensions'][0]['name']??null),$row['metrics']??[],count($metrics),$section);
                $truncated=(int)($r['total_rows']??count($rows))>count($rows);$quality=['sampled'=>(bool)($r['sampled']??false),'sample_share'=>isset($r['sample_share'])?(float)$r['sample_share']:null];
            }
            if(count($rows)>$limit)throw new \RuntimeException('Unexpected report row count');
            $result['sections'][$section]=['rows'=>$rows,'truncated'=>$truncated,'quality'=>$quality,'dimension'=>$dim];
        }
        return $result+['fetched_at'=>gmdate(DATE_ATOM)];
    }
    private function row(mixed $name,array $values,int $count,string $section): array
    {
        if(count($values)!==$count)throw new \RuntimeException('Unexpected analytics metric count');
        $numbers=[];foreach($values as $v){if(!is_numeric($v)||!is_finite((float)$v)|| (float)$v<0)throw new \RuntimeException('Invalid analytics metric');$numbers[]=(float)$v;}
        $name=is_string($name)?$name:'(unknown)';
        if($section==='daily'&&preg_match('/^\d{8}$/D',$name))$name=substr($name,0,4).'-'.substr($name,4,2).'-'.substr($name,6,2);
        if($section==='pages'){$name=preg_split('/[?#]/',$name,2)[0];$name=preg_replace('~^(https?://)[^/@]+@~i','$1',$name);} // Never persist landing-page query parameters.
        return ['label'=>substr($name,0,2048),'values'=>$numbers];
    }
}
