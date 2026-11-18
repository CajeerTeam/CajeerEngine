<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Identity/OidcClient.php';
use CajeerEngine\Identity\OidcClient;

$count=0;
$check=static function(bool $pass,string $message) use (&$count):void { if(!$pass)throw new RuntimeException('FAIL '.$message);$count++;echo "PASS $message\n"; };
$b64=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
$private=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
if($private===false)throw new RuntimeException('OpenSSL RSA unavailable');
$details=openssl_pkey_get_details($private); $rsa=$details['rsa'];
$jwks=json_encode(['keys'=>[['kid'=>'test-151','kty'=>'RSA','alg'=>'RS256','use'=>'sig','n'=>$b64($rsa['n']),'e'=>$b64($rsa['e'])]]],JSON_THROW_ON_ERROR);
$origin='https://id.skif4er.ru/realms/skif4er';
$meta=json_encode(['issuer'=>$origin,'authorization_endpoint'=>$origin.'/protocol/openid-connect/auth','token_endpoint'=>$origin.'/protocol/openid-connect/token','jwks_uri'=>$origin.'/protocol/openid-connect/certs'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$mode='valid';$seenBody='';$expectedNonce='';
$jwt = static function(array $claims, array $header=['kid'=>'test-151','alg'=>'RS256','typ'=>'JWT'])use($private,$b64):string {
    $sign=$b64(json_encode($header,JSON_THROW_ON_ERROR)).'.'.$b64(json_encode($claims,JSON_THROW_ON_ERROR));
    openssl_sign($sign,$signature,$private,OPENSSL_ALGO_SHA256);
    return $sign.'.'.$b64($signature);
};
$transport=static function(string $method,string $url,?string $body,array $headers)use(&$mode,&$seenBody,&$expectedNonce,$meta,$jwks,$jwt,$origin):array {
    if(str_ends_with($url,'/.well-known/openid-configuration')) {
        if($mode==='evil-discovery')return ['status'=>200,'body'=>str_replace('id.skif4er.ru/realms/skif4er/protocol','evil.example/realms/skif4er/protocol',$meta)];
        return ['status'=>200,'body'=>$meta];
    }
    if(str_ends_with($url,'/certs'))return ['status'=>200,'body'=>$jwks];
    if(str_ends_with($url,'/token')) {
        $seenBody=(string)$body;
        $claims=['iss'=>$origin,'aud'=>'cajeerengine-web','sub'=>'skif4er-test-sub','nonce'=>$expectedNonce,'exp'=>time()+300,'iat'=>time()-5,'amr'=>['pwd','otp']];
        if($mode==='bad-nonce')$claims['nonce']='wrong';
        if($mode==='bad-issuer')$claims['iss']='https://evil.example';
        if($mode==='bad-audience')$claims['aud']='other-client';
        if($mode==='expired')$claims['exp']=time()-100;
        if($mode==='oversized-id-token-lifetime')$claims['exp']=$claims['iat']+90000;
        if($mode==='invalid-id-token-sub')$claims['sub']="bad\nsubject";
        if($mode==='zero-issued-at')$claims['iat']=0;

        $signed=$jwt($claims);
        if($mode==='tampered')$signed=substr($signed,0,-5).'XXXXX';
        return ['status'=>200,'body'=>json_encode(['id_token'=>$signed,'access_token'=>'hidden-secret','refresh_token'=>'hidden-refresh'])];
    }
    throw new RuntimeException('Unexpected URL: '.$url);
};
$make=static fn()=>new OidcClient('cajeerengine-web','test-client-secret','https://cms.example.com/auth/oidc/callback',$transport);
$client=$make();$tx=$client->begin();$expectedNonce=$tx['nonce'];
$check(str_contains($tx['url'],'code_challenge_method=S256'),'PKCE S256 authorization URL');
$check(str_contains($tx['url'],'state='.$tx['state']),'state in authorization URL');
$check(str_contains($tx['url'],'nonce='.$tx['nonce']),'nonce in authorization URL');
parse_str(parse_url($tx['url'],PHP_URL_QUERY),$params);
$check(hash_equals($params['code_challenge'],$b64(hash('sha256',$tx['verifier'],true))),'PKCE challenge SHA-256');
$claims=$client->finish('valid-code',$tx['verifier'],$tx['nonce']);
$check($claims['sub']==='skif4er-test-sub','RS256 signature and claims accepted');
$check(str_contains($seenBody,'code_verifier='),'real token exchange uses code verifier');
$check(str_contains($seenBody,'client_secret=test-client-secret'),'confidential client authentication');
foreach(['bad-nonce','bad-issuer','bad-audience','expired','tampered','oversized-id-token-lifetime','invalid-id-token-sub','zero-issued-at'] as $bad) {
    $mode=$bad;
    try { $client->finish('valid-code',$tx['verifier'],$tx['nonce']); throw new RuntimeException('accepted bad token '.$bad); }
    catch(RuntimeException $e) { if(str_starts_with($e->getMessage(),'accepted bad'))throw $e; }
    $check(true,'reject '.$bad);
}
$mode='evil-discovery';
try { $make()->begin();throw new RuntimeException('accepted unsafe discovery'); }
catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'accepted'))throw $e;}
$check(true,'discovery endpoint cannot escape pinned host');
$mode='valid';
try {$client->finish('', $tx['verifier'], $tx['nonce']);throw new RuntimeException('accepted empty code');}
catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'accepted'))throw $e;}
$check(true,'invalid authorization code rejected');
$check(!str_contains(json_encode($claims), 'hidden-secret'), 'tokens not returned in validated claims');
echo "OIDC regression: $count PASS\n";
