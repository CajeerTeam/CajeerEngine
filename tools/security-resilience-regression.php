<?php

declare(strict_types=1);

/** Offline executable acceptance tests for key migration and integration circuit protection. */
$root = dirname(__DIR__);
if (is_file($root.'/vendor/autoload.php')) require $root.'/vendor/autoload.php';
else require $root.'/core/Console/NativeCli.php';

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\DeliveryCircuitBreaker;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Integration\IntegrationStore;
use CajeerEngine\Integration\CircuitOpen;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\KeyRotation;
use CajeerEngine\Update\OperationFiles as F;

function requireThat(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}
function fails(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException) { requireThat(true, $message); return; }
    throw new RuntimeException('FAIL: expected rejection: '.$message);
}
function oldEnvelope(string $master, string $aad, string $secret): string
{
    $raw=base64_decode(substr($master,7),true);
    $key=sodium_crypto_generichash('cajeerengine:integration-vault:v1',$raw,32);
    $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret,$aad,$nonce,$key));
}
function wipe(string $path): void
{
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isLink()) throw new RuntimeException('Unexpected symlink in test directory');
        if ($entry->isDir()) wipe($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
}

$tmp=sys_get_temp_dir().'/ce-keyrotate-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
try {
    // Discovery and JWKS GET requests may retry transient errors; single-use POSTs must not.
    $issuer='https://id.skif4er.ru/realms/skif4er';
    $requests=[];
    $oidcHttp=static function (string $method, string $url, ?string $body, array $headers) use (&$requests, $issuer): array {
        $requests[]=$method.' '.$url;
        if (count($requests)===1) return ['status'=>503,'body'=>'temporary'];
        return ['status'=>200,'body'=>json_encode([
            'issuer'=>$issuer,
            'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth',
            'token_endpoint'=>$issuer.'/protocol/openid-connect/token',
            'jwks_uri'=>$issuer.'/protocol/openid-connect/certs',
        ],JSON_THROW_ON_ERROR)];
    };
    $oidcClient=new \CajeerEngine\Identity\OidcClient('test-client','secret','https://cms.example/auth/oidc/callback',$oidcHttp(...));
    requireThat(str_contains($oidcClient->begin()['url'],'code_challenge_method=S256') && count($requests)===2,'OIDC retries transient read-only discovery once');
    $old='base64:'.base64_encode(random_bytes(32));
    $new='base64:'.base64_encode(random_bytes(32));
    $oldOidc='base64:'.base64_encode(random_bytes(32));
    $newOidc='base64:'.base64_encode(random_bytes(32));
    $vault=new SecretVault($new,[$old]);
    $oidc=new SecretVault($newOidc,[$oldOidc]);
    $legacy=oldEnvelope($old,'integration:v1:abc:http-json','historical-password');
    requireThat($vault->open('integration:v1:abc:http-json',$legacy)==='historical-password','legacy vault read with decrypt-only previous key');
    $rewrapped=$vault->rewrap('integration:v1:abc:http-json',$legacy);
    requireThat(str_starts_with($rewrapped,'v2:'.$vault->activeKeyId().':'),'new envelope carries key ID');
    requireThat($vault->rewrap('integration:v1:abc:http-json',$rewrapped)===$rewrapped,'rewrap idempotent');
    requireThat((new SecretVault($new))->open('integration:v1:abc:http-json',$rewrapped)==='historical-password','old key can be retired after successful rewrap');
    fails(fn()=>(new SecretVault($new))->open('integration:v1:abc:http-json',$legacy),'old key retirement denies old envelope');
    fails(fn()=> $vault->open('integration:v1:DIFFERENT:http-json',$rewrapped),'context mismatch fails closed');
    $parts=explode(':',$rewrapped,3); $data=base64_decode($parts[2],true);$data[26]=chr(ord($data[26])^1);
    fails(fn()=> $vault->open('integration:v1:abc:http-json',$parts[0].':'.$parts[1].':'.base64_encode($data)),'tampered envelope rejected');
    fails(fn()=> new SecretVault('base64:short')->seal('x','y'),'invalid master key rejected');
    $id='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $circuit=new DeliveryCircuitBreaker($tmp,2,60);
    requireThat($circuit->claim($id)===null,'closed circuit permits delivery');
    $circuit->record($id,null,503);$circuit->record($id,null,0);
    fails(fn()=> $circuit->claim($id),'repeated transient failures open circuit');
    $stateFile=$tmp.'/storage/app/integrations/circuits/'.hash('sha256',$id).'.json';
    $state=F::read($stateFile);$state['open_until']=time()-1;F::json($stateFile,$state);
    $ticket=$circuit->claim($id);
    requireThat(is_string($ticket)&&strlen($ticket)===32,'half-open probe claimed');
    fails(fn()=> $circuit->claim($id),'concurrent half-open request denied');
    $circuit->record($id,$ticket,204);
    requireThat($circuit->claim($id)===null,'successful probe closes circuit');
    $circuit->record($id,null,400);
    requireThat($circuit->claim($id)===null,'permanent 4xx do not open circuit');

    if (!in_array('sqlite', PDO::getAvailableDrivers(),true)) {
        if (in_array('--require-db',$argv,true)) throw new RuntimeException('PDO SQLite is mandatory for this acceptance run');
        echo "SKIP: real SQL rekey needs PDO SQLite\n";
        exit(0);
    }
    $cfg = new ConfigRepository($root);
    $cfg->set('database.default','sqlite');
    $cfg->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($cfg);
    $pdo=$db->connection();
    $pdo->exec('CREATE TABLE ce_oidc_transactions (state_hash TEXT PRIMARY KEY,verifier_ciphertext TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE analytics_connections (id TEXT PRIMARY KEY,provider TEXT NOT NULL,resource_id TEXT NOT NULL,credential TEXT)');
    $hash=hash('sha256','state');
    $pdo->prepare('INSERT INTO ce_oidc_transactions VALUES(?,?)')->execute([$hash,oldEnvelope($oldOidc,'oidc:pkce:'.$hash,'verifier')]);
    $pdo->prepare('INSERT INTO analytics_connections VALUES(?,?,?,?)')->execute(['report-01','ga4','resource-1',oldEnvelope($old,'analytics:report:v1:report-01:ga4:resource-1','analytics-key')]);
    $store=new IntegrationStore($tmp);
    $store->transaction(function (array &$items) use ($old): void {
        $items['connection-one']=['id'=>'connection-one','provider'=>'http-json','credential'=>oldEnvelope($old,'integration:v1:connection-one:http-json','integration-key')];
    },true);
    $projectId=str_repeat('a',32);
    $wxrRef=str_repeat('b',32);
    $mig=$tmp.'/storage/app/migration';F::dir($mig);
    F::json($mig.'/'.$projectId.'.json',[
        'id'=>$projectId,'source'=>'wordpress-wxr','credential'=>oldEnvelope($old,'migration:v1:'.$projectId.':wordpress-wxr','{}'),
        'wxr_ref'=>$wxrRef,
    ]);
    F::write($mig.'/'.$projectId.'-'.$wxrRef.'.wxr.enc',oldEnvelope($old,'migration:wxr:v1:'.$projectId,'<xml/>'));
    $privacy=$tmp.'/storage/app/privacy';F::dir($privacy);
    F::json($privacy.'/server-events.json',['grants'=>[
        'grant'=>['events'=>['event:connection-one'=>['ad_data'=>oldEnvelope($old,'analytics-ad:v1:grant:event:connection-one','ad-credential')]]],
        'active-grant'=>['expires'=>time()+900,'revoked'=>false,'events'=>['event:connection-one'=>['ad_data'=>oldEnvelope($old,'analytics-ad:v1:active-grant:event:connection-one','active-ad')]]],
    ]]);

    $rotation=new KeyRotation($tmp,$vault,$oidc,$db);
    $preview=$rotation->run(false);
    requireThat($preview['changed']===6 && $preview['deferred']===1 && !$preview['applied'],'dry run audits stores and defers active grant ciphertext');
    requireThat($rotation->run(true)['changed']===6,'apply rewraps all six encrypted records');
    $after=$rotation->run(false);
    requireThat($after['changed']===0 && $after['deferred']===1,'second audit leaves active grant data stable for queued idempotency');
    $activeState=F::read($privacy.'/server-events.json');
    $activeState['grants']['active-grant']['expires']=time()-1;
    F::json($privacy.'/server-events.json',$activeState);
    requireThat($rotation->run(true)['changed']===1 && $rotation->run(false)['deferred']===0,'expired grant is rekeyed safely');
    $withoutOld=new SecretVault($new);
    $current=(new IntegrationStore($tmp))->transaction(fn($items)=>$items['connection-one']['credential']);
    requireThat($withoutOld->open('integration:v1:connection-one:http-json',$current)==='integration-key','rekeyed integration usable without old key');
    $project=F::read($mig.'/'.$projectId.'.json');
    requireThat($withoutOld->open('migration:v1:'.$projectId.':wordpress-wxr',$project['credential'])==='{}','rekeyed migration credential usable');
    requireThat($withoutOld->open('migration:wxr:v1:'.$projectId,file_get_contents($mig.'/'.$projectId.'-'.$wxrRef.'.wxr.enc'))==='<xml/>','rekeyed WXR usable');
    $server=F::read($privacy.'/server-events.json');
    requireThat($withoutOld->open('analytics-ad:v1:grant:event:connection-one',$server['grants']['grant']['events']['event:connection-one']['ad_data'])==='ad-credential','rekeyed server analytics usable');
    requireThat($withoutOld->open('analytics:report:v1:report-01:ga4:resource-1',$pdo->query('SELECT credential FROM analytics_connections')->fetchColumn())==='analytics-key','rekeyed SQL reporting secret usable');
    requireThat((new SecretVault($newOidc))->open('oidc:pkce:'.$hash,$pdo->query('SELECT verifier_ciphertext FROM ce_oidc_transactions')->fetchColumn())==='verifier','rekeyed OIDC PKCE verifier usable');
    echo "PASS: security resilience acceptance completed\n";
} finally { wipe($tmp); }
