<?php

declare(strict_types=1);
namespace CajeerEngine\Privacy;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Analytics\Reporting\AlternativeReports;
use CajeerEngine\Update\OperationFiles as F;

/** Public counter identifiers only. Operator-approved HTTPS endpoints; no credentials or arbitrary JavaScript. */
final readonly class TrackingConfig
{
    public function __construct(private string $root) {}
    public const AD_PROVIDERS=['google_ads','meta','tiktok','vk'];
    public const AD_EVENTS=['generate_lead','form_submit','sign_up'];
    public static function advertisingDefaults(): array
    {
        return ['google_ads_id'=>'','meta_id'=>'','tiktok_id'=>'','vk_id'=>'','ads_lead_label'=>'','ads_form_label'=>'','ads_signup_label'=>'','ads_yandex_lead_goal'=>'','ads_yandex_form_goal'=>'','ads_yandex_signup_goal'=>'','ads_attribution'=>false,'ads_events'=>[]];
    }
    public static function adLabel(string $event): ?string
    {
        return match($event){'generate_lead'=>'ads_lead_label','form_submit'=>'ads_form_label','sign_up'=>'ads_signup_label',default=>null};
    }
    public static function yandexGoal(array $config,string $name): string
    {
        $key=match($name){'generate_lead'=>'ads_yandex_lead_goal','form_submit'=>'ads_yandex_form_goal','sign_up'=>'ads_yandex_signup_goal',default=>null};
        return $key===null?'':($config[$key]??'');
    }
    public static function adRoute(array $config,string $provider,string $name): bool
    {
        if($provider==='yandex_ads')return !empty($config['metrika_id'])&&in_array($name,$config['ads_events']??[],true)&&self::yandexGoal($config,$name)!=='';
        if(!in_array($provider,self::AD_PROVIDERS,true)||empty($config[$provider.'_id']))return false;
        if($name==='page_view')return $provider!=='google_ads';
        if(!in_array($name,$config['ads_events']??[],true))return false;
        return $provider!=='google_ads'||!empty($config[self::adLabel($name)]);
    }
    public static function defaults(): array
    {
        return ['revision'=>1,'enabled'=>false,'hosts'=>[],'metrika_id'=>'','ga4_id'=>'','gtm_id'=>'','ga4_debug'=>false,'matomo_id'=>'','matomo_url'=>'','plausible_id'=>'','plausible_url'=>'','cloudflare_token'=>'']+self::advertisingDefaults();
    }
    public function get(): array
    {
        $file=F::path($this->root,'storage/app/privacy/tracking.json');
        $value=is_file($file)?F::read($file):self::defaults();
         $value+=array_intersect_key(self::defaults(),array_flip(['matomo_id','matomo_url','plausible_id','plausible_url','cloudflare_token',...array_keys(self::advertisingDefaults())]));self::validate($value);
        return $value;
    }
    public function update(array $value): array
    {
        $dir=F::path($this->root,'storage/app/privacy'); F::dir($dir,0700);
        $lock=F::path($this->root,'storage/app/privacy/tracking.lock');
        $handle=fopen($lock,'c+b'); if(!$handle)throw new \RuntimeException('Cannot open tracking lock');
        try {
            F::permissions($lock,0600);
            if(!flock($handle,LOCK_EX))throw new \RuntimeException('Cannot lock tracking configuration');
            $current=$this->get();
            $value+=array_intersect_key($current,array_flip(['matomo_id','matomo_url','plausible_id','plausible_url','cloudflare_token',...array_keys(self::advertisingDefaults())]));self::validate($value);
            foreach(['matomo','plausible'] as $p)if($value[$p.'_id']!==''&&!self::allowedEndpoint($p,$value[$p.'_url']))throw new \InvalidArgumentException('Tracking endpoint not allowed by operator');
            if($current['revision']!==$value['revision'])throw new RevisionConflict('Tracking configuration changed; reload before saving.');
            $value['revision']++; F::json(F::path($this->root,'storage/app/privacy/tracking.json'),$value,0600);
            return $value;
        } finally { fclose($handle); }
    }
    public static function validate(array $v): void
    {
        if(array_diff_key($v,self::defaults())||array_diff_key(self::defaults(),$v))throw new \InvalidArgumentException('Invalid tracking fields');
        if(!is_int($v['revision'])||$v['revision']<1||$v['revision']>=PHP_INT_MAX-1||!is_bool($v['enabled'])||!is_bool($v['ga4_debug']))throw new \InvalidArgumentException('Invalid tracking revision or flags');
        foreach(['metrika_id'=>'~^[1-9][0-9]{0,11}$~D','ga4_id'=>'~^G-[A-Z0-9]{4,20}$~D','gtm_id'=>'~^GTM-[A-Z0-9]{4,20}$~D','cloudflare_token'=>'~^[a-f0-9]{32}$~D','google_ads_id'=>'~^AW-[0-9]{5,20}$~D','meta_id'=>'~^[1-9][0-9]{5,24}$~D','tiktok_id'=>'~^[A-Z0-9]{10,40}$~D','vk_id'=>'~^[1-9][0-9]{0,19}$~D','ads_lead_label'=>'~^[A-Za-z0-9_-]{1,100}$~D','ads_form_label'=>'~^[A-Za-z0-9_-]{1,100}$~D','ads_signup_label'=>'~^[A-Za-z0-9_-]{1,100}$~D','ads_yandex_lead_goal'=>'~^[A-Za-z0-9_-]{1,64}$~D','ads_yandex_form_goal'=>'~^[A-Za-z0-9_-]{1,64}$~D','ads_yandex_signup_goal'=>'~^[A-Za-z0-9_-]{1,64}$~D'] as $key=>$pattern){
            if(!is_string($v[$key])||($v[$key]!==''&&!preg_match($pattern,$v[$key])))throw new \InvalidArgumentException('Invalid '.$key);
        }
        if(!is_array($v['hosts'])||!array_is_list($v['hosts'])||count($v['hosts'])>50)throw new \InvalidArgumentException('Invalid tracking hosts');
        foreach($v['hosts'] as $host)if(!is_string($host)||strlen($host)>253||!preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$~D',$host))throw new \InvalidArgumentException('Use exact lowercase hostnames without ports or wildcards');
        if(count(array_unique($v['hosts']))!==count($v['hosts']))throw new \InvalidArgumentException('Duplicate tracking hosts');
        foreach(['matomo','plausible'] as $p){$id=$v[$p.'_id'];$url=$v[$p.'_url'];if(!is_string($id)||!is_string($url)||($id==='')!==($url==='')||($id!==''&&!AlternativeReports::validResource($p,$id)))throw new \InvalidArgumentException('Invalid '.$p.' counter');if($url!=='')AlternativeReports::base($url);}
        if(!is_bool($v['ads_attribution']))throw new \InvalidArgumentException('Invalid attribution flag');
        if(!is_array($v['ads_events'])||!array_is_list($v['ads_events'])||count($v['ads_events'])>3||count(array_unique($v['ads_events'],SORT_REGULAR))!==count($v['ads_events']))throw new \InvalidArgumentException('Invalid advertising events');
        foreach($v['ads_events'] as $name)if(!is_string($name)||!in_array($name,self::AD_EVENTS,true))throw new \InvalidArgumentException('Invalid advertising event');
        $ads=false;foreach(self::AD_PROVIDERS as $provider)$ads=$ads||$v[$provider.'_id']!=='';
        foreach(self::AD_EVENTS as $name)if($v[self::adLabel($name)]!==''&&($v['google_ads_id']===''||!in_array($name,$v['ads_events'],true)))throw new \InvalidArgumentException('Google Ads label requires an enabled conversion and Ads ID');
        foreach(self::AD_EVENTS as $name)if(self::yandexGoal($v,$name)!==''&&($v['metrika_id']===''||!in_array($name,$v['ads_events'],true)))throw new \InvalidArgumentException('Yandex advertising goal requires an enabled conversion and Metrika ID');
        if($v['gtm_id']!==''&&$ads)throw new \InvalidArgumentException('GTM and direct advertising tags are mutually exclusive');
        if($v['gtm_id']!==''&&($v['ga4_id']!==''||$v['metrika_id']!==''||$v['matomo_id']!==''||$v['plausible_id']!==''||$v['cloudflare_token']!==''))throw new \InvalidArgumentException('GTM and direct counters are mutually exclusive');
        if($v['enabled']&&($v['hosts']===[]||($v['metrika_id']===''&&$v['ga4_id']===''&&$v['gtm_id']===''&&$v['matomo_id']===''&&$v['plausible_id']===''&&$v['cloudflare_token']===''&&!$ads)))throw new \InvalidArgumentException('Enabled tracking requires a counter and explicit hosts');
        if($v['ga4_debug']&&$v['ga4_id']==='')throw new \InvalidArgumentException('GA4 debug requires GA4 ID');
    }
    public static function allowedEndpoint(string $provider,string $url): bool
    {
        $base=AlternativeReports::base($url);$origin='https://'.parse_url($base,PHP_URL_HOST);
        return ($provider==='plausible'&&$base==='https://plausible.io')||in_array($origin,ConsentPolicy::origins(),true);
    }
    /** Fixed vendor CSP sources, never derived from administrator-provided URLs. */
    public static function csp(array $config): array
    {
        if(!$config['enabled'])return ['script'=>[],'connect'=>[],'frame'=>[]];
        $script=[];$connect=[];$frame=[];
        if($config['metrika_id']!==''||$config['gtm_id']!==''){
            $script=array_merge($script,['https://mc.yandex.ru','https://yastatic.net']);
            $connect=array_merge($connect,['https://mc.yandex.ru','https://*.mc.yandex.ru']);
        }
        if($config['ga4_id']!==''||$config['gtm_id']!==''){
            $script[]='https://www.googletagmanager.com';
            $connect=array_merge($connect,['https://www.googletagmanager.com','https://*.google-analytics.com','https://*.analytics.google.com','https://*.google.com']);
        }
        if($config['gtm_id']!=='')$frame[]='https://www.googletagmanager.com';
        foreach(['matomo','plausible'] as $p)if(($config[$p.'_id']??'')!==''&&self::allowedEndpoint($p,$config[$p.'_url'])){
            $origin='https://'.parse_url($config[$p.'_url'],PHP_URL_HOST);$connect[]=$origin;if($p==='matomo')$script[]=$origin;
        }
        if(($config['cloudflare_token']??'')!==''){$script[]='https://static.cloudflareinsights.com';$connect[]='https://cloudflareinsights.com';}
        if(!empty($config['google_ads_id'])){$script=array_merge($script,['https://www.googletagmanager.com','https://www.googleadservices.com','https://www.google.com','https://*.doubleclick.net']);$connect=array_merge($connect,['https://*.google.com','https://*.googleadservices.com','https://*.doubleclick.net','https://*.google-analytics.com','https://www.googletagmanager.com']);$frame[]='https://*.doubleclick.net';$frame[]='https://www.googletagmanager.com';}
        if(!empty($config['meta_id'])){$script[]='https://connect.facebook.net';$connect[]='https://www.facebook.com';}
        if(!empty($config['tiktok_id'])){$script[]='https://analytics.tiktok.com';$connect[]='https://analytics.tiktok.com';}
        if(!empty($config['vk_id'])){$script[]='https://top-fwz1.mail.ru';$connect=array_merge($connect,['https://top-fwz1.mail.ru','https://top.mail.ru']);}
        return ['script'=>array_values(array_unique($script)),'connect'=>array_values(array_unique($connect)),'frame'=>array_values(array_unique($frame))];
    }
}
