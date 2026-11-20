<?php

declare(strict_types=1);
namespace CajeerEngine\Analytics;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Update\OperationFiles as F;

/** Versioned public event routing rules. No URLs, secrets or arbitrary scripts. */
final readonly class EventConfig
{
    public const PROVIDERS=['ga4','metrika','gtm','matomo','plausible'];
    public const AUTOMATIC=['search','file_download','outbound_click','scroll'];
    public const RESERVED=['purchase','refund','add_to_cart','begin_checkout','view_item'];
    public function __construct(private string $root) {}
    public static function defaults(): array
    {
        $rules=[];
        foreach(['page_view','search','file_download','outbound_click','scroll','form_submit','generate_lead','sign_up','login'] as $name){
            $rules[]=['name'=>$name,'enabled'=>true,'automatic'=>in_array($name,self::AUTOMATIC,true),'ga4'=>in_array($name,self::AUTOMATIC,true)?'site_'.$name:$name,'metrika'=>$name,'gtm'=>$name,'matomo'=>$name,'plausible'=>$name==='page_view'?'':$name];
        }
        return ['revision'=>1,'enabled'=>true,'allow_custom'=>true,'collect_diagnostics'=>false,'rules'=>$rules];
    }
    public function get(): array
    {
        $path=F::path($this->root,'storage/app/privacy/events.json');
        $data=is_file($path)?F::read($path):self::defaults();self::validate($data);return self::normalize($data);
    }
    public function update(array $data): array
    {
        self::validate($data);$dir=F::path($this->root,'storage/app/privacy');F::dir($dir,0700);
        $path=F::path($this->root,'storage/app/privacy/events.lock');$h=fopen($path,'c+b');if(!$h)throw new \RuntimeException('Cannot open events lock');
        try{F::permissions($path,0600);if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock events');
             $current=$this->get();if($data['revision']!==$current['revision'])throw new RevisionConflict('Event rules changed; reload before saving.');
             $previous=array_column($current['rules'],null,'name');foreach($data['rules'] as &$rule){$old=$previous[$rule['name']]??[];$rule+=['matomo'=>$old['matomo']??$rule['name'],'plausible'=>$old['plausible']??($rule['name']==='page_view'?'':$rule['name'])];}unset($rule);$data['revision']++;F::json($dir.'/events.json',$data,0600);return $data;
        }finally{fclose($h);}
    }
    public static function name(mixed $name): bool
    {
        return is_string($name)&&preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$name)===1&&!preg_match('/^(?:ce_|google_|ga_|firebase_|gtm_|session_|first_)/',$name)&&!in_array($name,self::RESERVED,true);
    }
    private static function normalize(array $data): array {
        foreach($data['rules'] as &$rule)$rule+=['matomo'=>$rule['name'],'plausible'=>$rule['name']==='page_view'?'':$rule['name']];unset($rule);return $data;
    }
    public static function validate(array $data): void
    {
        $fields=self::defaults();if(array_diff_key($data,$fields)||array_diff_key($fields,$data)||!is_int($data['revision'])||$data['revision']<1||$data['revision']>=PHP_INT_MAX-1)throw new \InvalidArgumentException('Invalid event configuration');
        foreach(['enabled','allow_custom','collect_diagnostics'] as $key)if(!is_bool($data[$key]))throw new \InvalidArgumentException('Invalid event flag');
        if(!is_array($data['rules'])||!array_is_list($data['rules'])||count($data['rules'])>100)throw new \InvalidArgumentException('Invalid event rules');
        $seen=[];
        foreach($data['rules'] as $rule){
            $keys=['name','enabled','automatic',...self::PROVIDERS];
            if(is_array($rule)&&isset($rule['name']))$rule+=['matomo'=>$rule['name'],'plausible'=>$rule['name']==='page_view'?'':$rule['name']];
            if(!is_array($rule)||count($rule)!==count($keys)||array_diff($keys,array_keys($rule))||!self::name($rule['name'])||isset($seen[$rule['name']])||!is_bool($rule['enabled'])||!is_bool($rule['automatic']))throw new \InvalidArgumentException('Invalid or duplicate event rule');
            $seen[$rule['name']]=true;
            if($rule['automatic']&&!in_array($rule['name'],self::AUTOMATIC,true))throw new \InvalidArgumentException('Unsupported automatic event');
            foreach(self::PROVIDERS as $provider){$target=$rule[$provider];if(!is_string($target)||($provider==='plausible'&&$target==='page_view')||($target!==''&&(!self::name($target)||($rule['name']!=='page_view'&&$target==='page_view'))))throw new \InvalidArgumentException('Invalid event mapping');
                if($rule['name']==='page_view'&&$target!==''&&$target!=='page_view')throw new \InvalidArgumentException('Page view mapping is fixed');}
        }
        if(!isset($seen['page_view']))throw new \InvalidArgumentException('Page view rule is required');
    }
    public static function rule(array $config,string $name): ?array
    {
        if(!$config['enabled']||!self::name($name))return null;
        foreach($config['rules'] as $rule)if($rule['name']===$name)return $rule['enabled']?$rule:null;
        return $config['allow_custom']?['name'=>$name,'enabled'=>true,'automatic'=>false,'ga4'=>$name,'metrika'=>$name,'gtm'=>$name,'matomo'=>$name,'plausible'=>$name]:null;
    }
}
