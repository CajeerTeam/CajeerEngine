<?php

declare(strict_types=1);
$root = dirname(__DIR__);
spl_autoload_register(static function (string $name) use ($root): void {
    if (str_starts_with($name,'CajeerEngine\\')) { $file=$root.'/core/'.str_replace('\\','/',substr($name,13)).'.php'; if(is_file($file))require $file; }
});
use CajeerEngine\Api\ApiContract;
use CajeerEngine\Rc\OpenApiLinter;
use CajeerEngine\Security\AuthService;
use CajeerEngine\Security\PublicHttpClient;
use CajeerEngine\Security\RateLimiter;
use CajeerEngine\Installer\SetupToken;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use CajeerEngine\Release\ReleaseBuilder;
use CajeerEngine\ImportExport\ImportExportService;
use CajeerEngine\Server\NginxConfigGenerator;
function ok(bool $v,string $msg): void { if(!$v)throw new RuntimeException($msg); echo "PASS $msg\n"; }
function reject(callable $f,string $msg): void { try{$f();}catch(Throwable){ok(true,$msg);return;}throw new RuntimeException($msg); }
$work=sys_get_temp_dir().'/ce-security-'.bin2hex(random_bytes(8));mkdir($work,0700);
try {
 $contract=new ApiContract($root);$doc=$contract->document();
 ok((new OpenApiLinter($root))->lint()[0]->status==='pass','route and contract parity');
 $schema=$contract->operation('api.v1.auth.login')['requestBody']['content']['application/json']['schema'];
 ok($contract->validate((object)['email'=>'admin@example.com','password'=>'strong-password'],$schema)===[],'valid login schema');
 ok($contract->validate((object)['email'=>'admin@example.com'],$schema)!==[],'missing password rejected');
 ok($contract->validate((object)['email'=>1,'password'=>[]],$schema)!==[],'invalid request types rejected');
 mkdir($work.'/api');copy($root.'/VERSION',$work.'/VERSION');
 $bad=$doc;unset($bad['paths']['/auth/login']);file_put_contents($work.'/api/openapi.yaml',json_encode($bad));ok((new OpenApiLinter($work))->lint()[0]->status==='fail','missing route rejected');
 $bad=$doc;$bad['paths']['/users']['get']['security']=[];file_put_contents($work.'/api/openapi.yaml',json_encode($bad));ok((new OpenApiLinter($work))->lint()[0]->status==='fail','authorization drift rejected');
 ok(AuthService::intersectScopes(['*'],['content:read'])===['content:read'],'stale wildcard constrained by current role');
 ok(AuthService::intersectScopes(['content:read'],['*'])===['content:read'],'token cannot inherit admin scope');
 ok(AuthService::intersectScopes(['content:write'],['content:read'])===[],'revoked permission removed');
 ok(AuthService::csrfToken('one')!==AuthService::csrfToken('two'),'CSRF bound to token');
 ok(\CajeerEngine\Security\SafeHtml::url('javascript:alert(1)')==='' && \CajeerEngine\Security\SafeHtml::url('/page')==='/page','unsafe link denied');
 $safe=\CajeerEngine\Security\SafeHtml::sanitize('<p>hello</p><script>alert(1)</script><img src=javascript:alert(1) onerror=alert(1)>');
 ok(!str_contains($safe,'<script>')&&!str_contains($safe,'<img src=javascript:'),'active HTML removed or escaped');
 if(class_exists(DOMDocument::class))ok(str_contains($safe,'<p>hello</p>')&&!str_contains($safe,'onerror'),'rich HTML preserved without event handlers');
 $limiter=new RateLimiter($work);ok($limiter->hit('auth','client',1,60)['allowed'],'first rate limit hit allowed');ok(!$limiter->hit('auth','client',1,60)['allowed'],'second rate limit hit rejected');
 $token=SetupToken::issue($work);ok(SetupToken::verify($work,$token),'setup token accepted');ok(!str_contains(file_get_contents($work.'/storage/app/setup-token.json'),$token),'setup token stored hashed');
 $new=SetupToken::issue($work);ok(!SetupToken::verify($work,$token)&&SetupToken::verify($work,$new),'old setup token invalidated');SetupToken::consume($work);ok(!SetupToken::verify($work,$new),'consumed setup token rejected');
 foreach(['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','::1','::ffff:127.0.0.1','64:ff9b::7f00:1','2002:7f00:1::','224.0.0.1'] as $ip)ok(!PublicHttpClient::isPublicIp($ip),'SSRF rejected '.$ip);
 ok(PublicHttpClient::isPublicIp('8.8.8.8'),'public address allowed');
 reject(fn()=>PublicHttpClient::target('http://127.0.0.1/'),'loopback URL rejected');reject(fn()=>PublicHttpClient::target('file:///etc/passwd'),'non-HTTP scheme rejected');reject(fn()=>PublicHttpClient::target('http://8.8.8.8:8080/'),'nonstandard port rejected');
 $config=new ConfigRepository($work);$media=new MediaRepository($work,new StorageManager($work,$config));
 $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==';
 $item=$media->createFromBase64(['filename'=>'pixel.png','mime_type'=>'text/html','content_base64'=>$png]);ok($item['mime_type']==='image/png'&&$item['width']===1,'server MIME and image dimensions');
 reject(fn()=> $media->createFromBase64(['filename'=>'fake.png','mime_type'=>'image/png','content_base64'=>base64_encode('<html><script>bad</script></html>')]),'spoofed image rejected');
 reject(fn()=> $media->createFromBase64(['filename'=>'x.svg','content_base64'=>base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>')]),'SVG upload rejected');
 ok($media->count()===1,'failed upload does not add media');
 $import=new ImportExportService($work);$export=$import->createExport(['media'],'test.json');
 ok($import->diff($export['path'])['ok'],'export checksum roundtrip');
 reject(fn()=> $import->import($export['path'],['delete_missing_content'=>true]),'destructive import blocked');
 reject(fn()=> $import->import($export['path'],['create_missing_users'=>true]),'privileged provisioning blocked');
 $payload=json_decode(file_get_contents($export['path']),true);$payload['data']['settings']=['injected'=>true];file_put_contents($export['path'],json_encode($payload));reject(fn()=> $import->diff($export['path']),'tampered export rejected');
 $manager=new UpdateManager($work,$config);reject(fn()=> $manager->apply(['package'=>'/tmp/no.zip','force'=>true]),'unsigned update blocked even with force');reject(fn()=> $manager->rollback(),'unconfirmed rollback blocked');
 $release=(new ReleaseBuilder($root))->build(false,'source',$work.'/release');
 ok(!$manager->verifyPackage($release['target'],$release['sha256'])['ok'],'unsigned real release rejected');\CajeerEngine\Update\ReleaseSignature::generate($work.'-sign.key',$work.'-sign.pub');putenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE='.$work.'-sign.pub');\CajeerEngine\Update\ReleaseSignature::sign($release['target'],$work.'-sign.key');ok($manager->verifyPackage($release['target'],$release['sha256'])['ok'],'signed real release verifies');ok(!$manager->verifyPackage($release['target'],str_repeat('0',64))['ok'],'wrong external checksum rejected');
 $nginx=(new NginxConfigGenerator($work))->generate()['config'];ok(str_contains($nginx,'location ~* \\.(?:php')&&!str_contains($nginx,'location ~ \\.php$'),'Nginx denies arbitrary PHP execution');
 reject(fn()=> (new NginxConfigGenerator($work))->generate(['root'=>"/tmp/x;\ninclude /etc/passwd;"]),'Nginx config injection rejected');
}finally{
 foreach([$work.'-sign.key',$work.'-sign.pub'] as $key)if(is_file($key))unlink($key);
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($work);
}
