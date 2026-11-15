<?php

declare(strict_types=1);
namespace CajeerEngine\Privacy;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Update\OperationFiles as F;

/** Public counter identifiers only. No arbitrary URLs, credentials or JavaScript. */
final readonly class TrackingConfig
{
    public function __construct(private string $root) {}
    public static function defaults(): array
    {
        return ['revision'=>1,'enabled'=>false,'hosts'=>[],'metrika_id'=>'','ga4_id'=>'','gtm_id'=>'','ga4_debug'=>false];
    }
    public function get(): array
    {
        $file=F::path($this->root,'storage/app/privacy/tracking.json');
        $value=is_file($file)?F::read($file):self::defaults();
        self::validate($value);
        return $value;
    }
    public function update(array $value): array
    {
        self::validate($value);
        $dir=F::path($this->root,'storage/app/privacy'); F::dir($dir,0700);
        $lock=F::path($this->root,'storage/app/privacy/tracking.lock');
        $handle=fopen($lock,'c+b'); if(!$handle)throw new \RuntimeException('Cannot open tracking lock');
        try {
            F::permissions($lock,0600);
            if(!flock($handle,LOCK_EX))throw new \RuntimeException('Cannot lock tracking configuration');
            $current=$this->get();
            if($current['revision']!==$value['revision'])throw new RevisionConflict('Tracking configuration changed; reload before saving.');
            $value['revision']++; F::json(F::path($this->root,'storage/app/privacy/tracking.json'),$value,0600);
            return $value;
        } finally { fclose($handle); }
    }
    public static function validate(array $v): void
    {
        if(array_diff_key($v,self::defaults())||array_diff_key(self::defaults(),$v))throw new \InvalidArgumentException('Invalid tracking fields');
        if(!is_int($v['revision'])||$v['revision']<1||$v['revision']>=PHP_INT_MAX-1||!is_bool($v['enabled'])||!is_bool($v['ga4_debug']))throw new \InvalidArgumentException('Invalid tracking revision or flags');
        foreach(['metrika_id'=>'~^[1-9][0-9]{0,11}$~D','ga4_id'=>'~^G-[A-Z0-9]{4,20}$~D','gtm_id'=>'~^GTM-[A-Z0-9]{4,20}$~D'] as $key=>$pattern){
            if(!is_string($v[$key])||($v[$key]!==''&&!preg_match($pattern,$v[$key])))throw new \InvalidArgumentException('Invalid '.$key);
        }
        if(!is_array($v['hosts'])||!array_is_list($v['hosts'])||count($v['hosts'])>50)throw new \InvalidArgumentException('Invalid tracking hosts');
        foreach($v['hosts'] as $host)if(!is_string($host)||strlen($host)>253||!preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$~D',$host))throw new \InvalidArgumentException('Use exact lowercase hostnames without ports or wildcards');
        if(count(array_unique($v['hosts']))!==count($v['hosts']))throw new \InvalidArgumentException('Duplicate tracking hosts');
        if($v['gtm_id']!==''&&($v['ga4_id']!==''||$v['metrika_id']!==''))throw new \InvalidArgumentException('GTM and direct counters are mutually exclusive');
        if($v['enabled']&&($v['hosts']===[]||($v['metrika_id']===''&&$v['ga4_id']===''&&$v['gtm_id']==='')))throw new \InvalidArgumentException('Enabled tracking requires a counter and explicit hosts');
        if($v['ga4_debug']&&$v['ga4_id']==='')throw new \InvalidArgumentException('GA4 debug requires GA4 ID');
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
        return ['script'=>$script,'connect'=>$connect,'frame'=>$frame];
    }
}
