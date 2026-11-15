<?php

declare(strict_types=1);
use CajeerEngine\Privacy\{TrackingConfig,ConsentPolicy};
use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Http\RouteRegistry;
use CajeerEngine\Api\ApiContract;
use CajeerEngine\Update\OperationFiles as F;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void {if(str_starts_with($class,'CajeerEngine\\')){ $p=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function reject(callable $fn,string $class,string $msg):void {try{$fn();}catch(Throwable $e){check($e instanceof $class,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);}
$work=sys_get_temp_dir().'/ce-tracking-'.bin2hex(random_bytes(8));mkdir($work,0700);
try {
 $cfg=new TrackingConfig($work);$d=$cfg->get();check($d===TrackingConfig::defaults()&&!$d['enabled'],'disabled by default');
 $policy=new ConsentPolicy($work);$v=$policy->publicConfig()['version'];
 $c=$cfg->update(array_replace($d,['enabled'=>true,'hosts'=>['site.example'],'metrika_id'=>'12345678','ga4_id'=>'G-ABC1234567','ga4_debug'=>true]));
 check($c['revision']===2&&(new TrackingConfig($work))->get()===$c,'persistent configuration');
 check((fileperms($work.'/storage/app/privacy/tracking.json')&0777)===0600,'private settings file');
 check($policy->publicConfig()['version']!==$v&&$policy->publicConfig()['tracking']===$c,'tracking revision invalidates consent and exposed config');
 reject(fn()=>$cfg->update($d),RevisionConflict::class,'concurrent stale revision rejected');
 foreach([['hosts'=>[]],['hosts'=>['*.example.com']],['hosts'=>['https://site.example']],['hosts'=>['site.example:443']],['hosts'=>['SITE.EXAMPLE']],['hosts'=>['site.example','site.example']],['ga4_id'=>'https://evil.test'],['metrika_id'=>'1;alert(1)'],['gtm_id'=>'GTM-ABCDE'],['gtm_id'=>'<script>'],['enabled'=>'true'],['extra'=>'x']] as $bad)reject(fn()=>$cfg->update(array_replace($c,$bad)),InvalidArgumentException::class,'invalid configuration rejected: '.json_encode($bad));
 $origins=TrackingConfig::csp($c);check(in_array('https://mc.yandex.ru',$origins['script'],true)&&in_array('https://www.googletagmanager.com',$origins['script'],true)&&in_array('https://*.google-analytics.com',$origins['connect'],true),'fixed CSP origins for direct providers');
 $gtm=$cfg->update(array_replace($c,['metrika_id'=>'','ga4_id'=>'','gtm_id'=>'GTM-ABC123','ga4_debug'=>false]));check(in_array('https://www.googletagmanager.com',TrackingConfig::csp($gtm)['frame'],true),'exclusive GTM config and CSP');
 $disabled=$cfg->update(array_replace($gtm,['enabled'=>false]));check(TrackingConfig::csp($disabled)===['script'=>[],'connect'=>[],'frame'=>[]],'disabled config does not expand CSP');
 $routes=array_column(RouteRegistry::all(),5,2);check($routes['api.v1.tracking.counters.index']===['tracking:read']&&$routes['api.v1.tracking.counters.update']===['tracking:write'],'dedicated access scopes');
 $contract=new ApiContract($root);$schema=$contract->operation('api.v1.tracking.counters.update')['requestBody']['content']['application/json']['schema'];check($contract->validate(json_decode(json_encode($c)), $schema)===[],'valid API config');
 check($contract->validate((object)['revision'=>1],$schema)!==[],'incomplete API config rejected');
 $schema=['$ref'=>'#/components/schemas/PublicConsentPolicy'];check($contract->validate(json_decode(json_encode($policy->publicConfig())), $schema)===[],'public policy response matches schema');
 foreach(['consent.js','consent.css'] as $name)F::copy($root.'/resources/consent/'.$name,$work.'/resources/consent/'.$name,0644);
 $html=$policy->decorate('<html><head></head><body>page</body></html>');check(str_contains($html,'data-ce-consent-config')&&!str_contains($html,'src="https://'),'neutral HTML contains no live vendor script');
 check(str_contains($html,'GTM-ABC123'),'static snapshot carries saved configuration');
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($work);}
