<?php

declare(strict_types=1);
use CajeerEngine\Privacy\ConsentPolicy;
use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Http\RouteRegistry;
use CajeerEngine\Api\ApiContract;
use CajeerEngine\Release\ReleaseFilePolicy;
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void {if(str_starts_with($class,'CajeerEngine\\')){ $p=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
function reject(callable $fn,string $class,string $msg):void {try{$fn();}catch(Throwable $e){check($e instanceof $class,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);}
$work=sys_get_temp_dir().'/ce-privacy-'.bin2hex(random_bytes(8));mkdir($work,0700);
try {
 foreach(['consent.js','consent.css'] as $name) \CajeerEngine\Update\OperationFiles::copy($root.'/resources/consent/'.$name,$work.'/resources/consent/'.$name,0644);
 $p=new ConsentPolicy($work);$d=$p->get();check($d['revision']===1&&$d['ttl_days']===180,'default policy');
 $v=$p->publicConfig()['version'];check(strlen($v)===64,'stable public version');
 $d['title_en']='<script>alert(1)</script>'; $u=$p->update($d);check($u['revision']===2&&(new ConsentPolicy($work))->get()===$u,'durable policy update');
 check($p->publicConfig()['version']!==$v,'policy change invalidates consent');
 check((fileperms($work.'/storage/app/privacy/policy.json')&0777)===0600,'private policy file');
 reject(fn()=>$p->update($d),RevisionConflict::class,'stale revision rejected');
 foreach(['//evil.test','javascript:alert(1)','/a%2fb','https://evil.test','/a\\b'] as $url)reject(fn()=>$p->update(array_replace($u,['policy_url'=>$url])),InvalidArgumentException::class,'unsafe policy link rejected');
 reject(fn()=>$p->update(array_replace($u,['ttl_days'=>0])),InvalidArgumentException::class,'invalid TTL rejected');
 reject(fn()=>$p->update($u+['secret'=>'test']),InvalidArgumentException::class,'unknown field rejected');
 putenv('CONSENT_SCRIPT_ORIGINS=https://tracker.example,http://evil.example,https://evil.example/path,https://tracker.example');check(ConsentPolicy::origins()===['https://tracker.example'],'exact HTTPS allowlist');
 $html=$p->decorate('<html><head></head><body><script type="text/plain" data-ce-consent="analytics" data-ce-src="https://tracker.example/tag.js"></script></body></html>');
 check(str_contains($html,'data-ce-consent-dynamic="1"')&&str_contains($html,'/assets/ce-consent/consent.js')&&!str_contains($html,'<script>alert(1)</script>'),'escaped neutral public markup');
 check(!str_contains($html,' src="https://tracker.example/tag.js"'),'optional descriptor inert in HTML');
 $routes=array_column(RouteRegistry::all(),5,2);check($routes['api.v1.privacy.consent.index']===['privacy:read']&&$routes['api.v1.privacy.consent.update']===['privacy:write']&&array_key_exists('api.v1.public.consent',$routes)&&$routes['api.v1.public.consent']===null,'public and protected route policy');
 $contract=new ApiContract($root);$schema=$contract->operation('api.v1.privacy.consent.update')['requestBody']['content']['application/json']['schema'];check($contract->validate(json_decode(json_encode($u)), $schema)===[],'valid policy request schema');check($contract->validate((object)['revision'=>2],$schema)!==[],'invalid policy request rejected');
 foreach(['consent.js','consent.css'] as $asset)check(ReleaseFilePolicy::allows('resources/consent/'.$asset,'dist'),'release includes '.$asset);
 [$status,$headers,$body]=\CajeerEngine\Privacy\ConsentAssets::response($work,'/assets/ce-consent/consent.js','GET');
 check($status===200&&str_contains($headers['Content-Type'],'javascript')&&$body===file_get_contents($work.'/resources/consent/consent.js'),'served real JavaScript asset');
 check(\CajeerEngine\Privacy\ConsentAssets::response($work,'/assets/ce-consent/consent.js','GET',$headers['ETag'])[0]===304,'asset cache revalidation');
 check(\CajeerEngine\Privacy\ConsentAssets::response($work,'/assets/ce-consent/consent.css','HEAD')[2]==='','asset HEAD has no body');
 check(\CajeerEngine\Privacy\ConsentAssets::response($work,'/assets/ce-consent/../secret','GET')[0]===404,'asset path traversal rejected');
}finally{putenv('CONSENT_SCRIPT_ORIGINS');foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($work);}
