<?php

declare(strict_types=1);

use CajeerEngine\Api\ApiContract;
use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Integration\IntegrationCenter;
use CajeerEngine\Integration\IntegrationStore;
use CajeerEngine\Integration\Provider\HttpProvider;
use CajeerEngine\Integration\Provider\ProviderRegistry;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\OutboundHttpTransport;
use CajeerEngine\Security\PublicHttpClient;
use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Backup\RecoveryBundle;

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class,'CajeerEngine\\')) {
        $path=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if (is_file($path)) require $path;
    }
});
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
function reject(callable $call,string $class,string $message): void { try { $call(); } catch (Throwable $e) { check($e instanceof $class,$message.' ('.$e::class.')'); return; } throw new RuntimeException('Expected rejection: '.$message); }
$work=sys_get_temp_dir().'/ce-integrations-'.bin2hex(random_bytes(8)); mkdir($work,0700);
try {
    $http=new class implements OutboundHttpTransport {
        public array $calls=[];
        public int $status=202;
        public function request(string $url,string $method,array $headers=[],string $body='',int $maxBytes=1048576): array {
            $this->calls[]=compact('url','method','headers','body','maxBytes');
            if ($this->status===0) throw new RuntimeException('SENSITIVE remote error');
            return [$this->status,'SENSITIVE remote response'];
        }
    };
    $key='base64:'.base64_encode(random_bytes(32)); $vault=new SecretVault($key);
    $sealed=$vault->seal('one','my-secret'); check($vault->open('one',$sealed)==='my-secret','vault round trip');
    reject(fn()=> $vault->open('two',$sealed),RuntimeException::class,'ciphertext context binding');
    reject(fn()=> (new SecretVault('base64:'.base64_encode(random_bytes(32))))->open('one',$sealed),RuntimeException::class,'wrong key rejected');
    $separator=strrpos($sealed,':');$prefix=substr($sealed,0,$separator+1);
    $raw=base64_decode(substr($sealed,$separator+1),true);$raw[30]=chr(ord($raw[30])^1);
    reject(fn()=> $vault->open('one',$prefix.base64_encode($raw)),RuntimeException::class,'tampered ciphertext rejected');
    reject(fn()=> (new SecretVault(''))->seal('one','x'),RuntimeException::class,'missing key fails closed');
    $registry=new ProviderRegistry();$registry->register(new HttpProvider(false,$http));$registry->register(new HttpProvider(true,$http));
    reject(fn()=> $registry->register(new HttpProvider(false,$http)),InvalidArgumentException::class,'duplicate provider rejected');
    $config=new ConfigRepository($work);$store=new IntegrationStore($work);$center=new IntegrationCenter($store,$registry,$vault,$config);
    $input=['provider'=>'http-json','name'=>'Receiver','config'=>['url'=>'https://example.com/receive'],'secret'=>'test-bearer-token','enabled'=>true];
    foreach (['http://example.com','https://127.0.0.1','https://[::1]','https://user:password@example.com','https://example.com/?token=secret','https://example.com:8443'] as $url) {
        reject(fn()=> $center->create(array_replace($input,['config'=>['url'=>$url]]),'actor'),InvalidArgumentException::class,'unsafe URL rejected: '.$url);
    }
    $one=$center->create($input,'actor'); $id=$one['id'];
    check($one['secret_configured'] && !isset($one['credential'],$one['secret']),'create returns no credential');
    $file=$work.'/storage/app/integrations/registry.json';check(!str_contains(file_get_contents($file),'test-bearer-token') && (fileperms($file)&0777)===0600,'encrypted private registry on disk');
    $center=new IntegrationCenter(new IntegrationStore($work),$registry,$vault,$config);
    check(count($center->overview()['items'])===1 && !str_contains(json_encode($center->overview()),'test-bearer-token'),'persisted public overview');
    $result=$center->run($id,1,'content.saved',['id'=>42],'actor');$call=end($http->calls);
    check($result['ok'] && $result['status_code']===202 && $call['method']==='POST' && $call['headers']['Authorization']==='Bearer test-bearer-token','HTTP provider sends actual request with bearer');
    check(json_decode($call['body'],true)['payload']['id']===42 && $call['headers']['Idempotency-Key']===$result['event_id'],'event payload and idempotency identifier');
    check(!str_contains(json_encode($center->overview()),'SENSITIVE'),'remote response never persisted');
    $http->status=401; $result=$center->run($id,1,'manual.send',[],'actor');check(!$result['ok'] && $result['error']==='remote_rejected','remote rejection recorded');
    $http->status=0; $result=$center->run($id,1,'manual.send',[],'actor');check(!$result['ok'] && $result['error']==='transport_failed' && !str_contains(json_encode($result),'SENSITIVE'),'transport error sanitized');
    reject(fn()=> $center->update($id,['revision'=>1,'config'=>['url'=>'https://other.example/receive']],'actor'),InvalidArgumentException::class,'credential-bound destination cannot change');
    $two=$center->rotate($id,1,'new-bearer-token','actor');check($two['revision']===2,'secret rotated');
    reject(fn()=> $center->update($id,['revision'=>1,'name'=>'Stale'],'actor'),RevisionConflict::class,'stale update rejected');
    $http->status=200;$center->run($id,2,'manual.send',[],'actor');check(end($http->calls)['headers']['Authorization']==='Bearer new-bearer-token','new credential used');
    $three=$center->update($id,['revision'=>2,'enabled'=>false],'actor');reject(fn()=> $center->run($id,3,'manual.send',[],'actor'),InvalidArgumentException::class,'disabled integration cannot send');
    check($center->run($id,3,'integration.test',[],'actor',true)['ok'],'explicit test works while disabled');
    $config->set('integrations.enabled',false);reject(fn()=> $center->run($id,3,'integration.test',[],'actor',true),RuntimeException::class,'global switch stops tests');$config->set('integrations.enabled',true);
    $config->set('integrations.allowed_hosts',['other.example']);reject(fn()=> $center->run($id,3,'integration.test',[],'actor',true),InvalidArgumentException::class,'host allowlist applied at execution');$config->set('integrations.allowed_hosts',[]);
    $four=$center->rotate($id,3,'','actor');check(!$four['secret_configured'],'optional bearer removed');
    $five=$center->update($id,['revision'=>4,'config'=>['url'=>'https://other.example/receive']],'actor');check($five['config']['url']==='https://other.example/receive','uncredentialed destination editable');
    $signed=$center->create(['provider'=>'signed-webhook','name'=>'Signed','config'=>['url'=>'https://example.com/hook'],'secret'=>'long-hmac-secret-for-test','enabled'=>true],'actor');
    $result=$center->run($signed['id'],1,'content.saved',['id'=>1],'actor');$call=end($http->calls);
    check((new SignedPayload())->verify($call['body'],$call['headers']['X-CajeerEngine-Signature'],'long-hmac-secret-for-test') && $call['headers']['X-CajeerEngine-Event-Id']===$result['event_id'],'real signed webhook envelope and HMAC');
    reject(fn()=> $center->rotate($signed['id'],1,'','actor'),InvalidArgumentException::class,'required HMAC secret cannot be removed');
    reject(fn()=> $center->run($signed['id'],1,'invalid event',[],'actor'),InvalidArgumentException::class,'event header injection blocked');
    reject(fn()=> $center->run($signed['id'],1,'ok',['x'=>str_repeat('x',70000)],'actor'),InvalidArgumentException::class,'oversized payload blocked');
    reject(fn()=> $center->delete($id,4),RevisionConflict::class,'stale delete rejected');$center->delete($id,5);check(count($center->overview()['items'])===1,'delete persisted');
    $contract=new ApiContract($root);$op=$contract->operation('api.v1.integrations.secret');check($op['x-cajeer-required-scopes']===['integrations:write','integrations:secrets'],'credential RBAC scope');
    check($contract->validate((object)['revision'=>1,'secret'=>'secret'],$op['requestBody']['content']['application/json']['schema'])===[],'credential request schema');
    check($contract->validate((object)['revision'=>1,'secret'=>'secret','config'=>(object)[]],$op['requestBody']['content']['application/json']['schema'])!==[],'unknown credential fields rejected');
    reject(fn()=> PublicHttpClient::target('https://127.0.0.1/'),InvalidArgumentException::class,'real transport SSRF guard');
    check((new \CajeerEngine\Rc\OpenApiLinter($root))->lint()[0]->status==='pass','OpenAPI route and RBAC parity');
} finally { RecoveryBundle::cleanup($work); }
