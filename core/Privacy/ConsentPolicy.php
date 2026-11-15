<?php

declare(strict_types=1);
namespace CajeerEngine\Privacy;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Update\OperationFiles as F;

/** Public policy, never visitor identities or credentials. Atomic revision-controlled writes. */
final readonly class ConsentPolicy
{
    public function __construct(private string $root) {}
    public static function defaults(): array
    {
        return ['revision'=>1,'title_ru'=>'Настройки приватности','message_ru'=>'Мы используем необходимые технологии для работы сайта. Необязательные технологии включаются только с вашего согласия.',
            'title_en'=>'Privacy preferences','message_en'=>'We use necessary technologies to operate this site. Optional technologies are enabled only with your consent.',
            'policy_url'=>'','ttl_days'=>180];
    }
    public function get(): array
    {
        $file=F::path($this->root,'storage/app/privacy/policy.json');
        $policy=is_file($file)?F::read($file):self::defaults();
        $this->validate($policy);
        return $policy;
    }
    public function update(array $input): array
    {
        $this->validate($input);
        $dir=F::path($this->root,'storage/app/privacy'); F::dir($dir,0700);
        $lock=F::path($this->root,'storage/app/privacy/policy.lock');
        $handle=fopen($lock,'c+b'); if(!$handle)throw new \RuntimeException('Cannot open privacy policy lock');
        try {
            F::permissions($lock,0600);
            if(!flock($handle,LOCK_EX))throw new \RuntimeException('Cannot lock privacy policy');
            $current=$this->get();
            if($input['revision']!==$current['revision'])throw new RevisionConflict('Privacy policy changed; reload before saving.');
            $input['revision']++; F::json(F::path($this->root,'storage/app/privacy/policy.json'),$input,0600);
            return $input;
        } finally { fclose($handle); }
    }
    private function validate(array $value): void
    {
        if(array_diff_key($value,self::defaults())||array_diff_key(self::defaults(),$value))throw new \InvalidArgumentException('Invalid privacy policy fields');
        if(!is_int($value['revision'])||$value['revision']<1||$value['revision']>=PHP_INT_MAX-1||!is_int($value['ttl_days'])||$value['ttl_days']<1||$value['ttl_days']>365)throw new \InvalidArgumentException('Invalid policy revision or retention');
        foreach(['title_ru'=>200,'title_en'=>200,'message_ru'=>4000,'message_en'=>4000] as $field=>$max){
            if(!is_string($value[$field])||trim($value[$field])===''||strlen($value[$field])>$max||!preg_match('//u',$value[$field]))throw new \InvalidArgumentException('Invalid '.$field);
        }
        $url=$value['policy_url'];
        if(!is_string($url)||strlen($url)>512||($url!==''&&!preg_match('~^/(?!/)[a-zA-Z0-9._/%-]*$~D',$url))||preg_match('~%(?:2f|5c|00|0a|0d)~i',$url))throw new \InvalidArgumentException('Privacy policy URL must be a local path');
    }
    /** Exact HTTPS origins, operator-controlled, shared with public CSP. Invalid values fail closed. */
    public static function origins(): array
    {
        $origins=[];
        foreach(explode(',',(string)getenv('CONSENT_SCRIPT_ORIGINS')) as $origin){
            $origin=trim($origin);
            if(preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[1-9][0-9]{0,4})?$~D',$origin)&&filter_var($origin,FILTER_VALIDATE_URL))$origins[]=$origin;
        }
        return array_values(array_unique($origins));
    }
    public function publicConfig(): array
    {
        $policy=$this->get(); $origins=self::origins();
        $tracking=(new TrackingConfig($this->root))->get();
        $events=(new \CajeerEngine\Analytics\EventConfig($this->root))->get();
        return $policy+['script_origins'=>$origins,'tracking'=>$tracking,'events'=>$events,'version'=>hash('sha256',json_encode([$policy,$origins,$tracking,$events],JSON_THROW_ON_ERROR))];
    }
    /** Neutral HTML after render-cache lookup. No user-specific data and no tracker src attributes. */
    public function decorate(string $html): string
    {
        try { $config=$this->publicConfig(); $asset=ConsentAssets::version($this->root); } catch(\Throwable) { return $html; } // no managed tracker can load without runtime
        $json=htmlspecialchars(json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $markup='<link rel="stylesheet" href="/assets/ce-consent/consent.css?v='.$asset.'"><script defer src="/assets/ce-consent/consent.js?v='.$asset.'" data-ce-consent-config="'.$json.'" data-ce-consent-dynamic="1"></script>';
        $pos=strripos($html,'</head>'); if($pos!==false)return substr_replace($html,$markup,$pos,0);
        $pos=strripos($html,'</body>'); return $pos===false?$html.$markup:substr_replace($html,$markup,$pos,0);
    }
}
