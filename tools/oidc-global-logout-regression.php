<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/core/Identity/OidcClient.php';
use CajeerEngine\Identity\OidcClient;

$pass=0;
$ok=static function(bool $value, string $name) use (&$pass):void {if (!$value) throw new RuntimeException('FAIL '.$name); $pass++; echo 'PASS '.$name.PHP_EOL;};
$b64=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
if (!$key) throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($key)['rsa'];
$origin='https://id.skif4er.ru/realms/skif4er';
$config=json_encode(['issuer'=>$origin,'authorization_endpoint'=>$origin.'/protocol/openid-connect/auth','token_endpoint'=>$origin.'/protocol/openid-connect/token','jwks_uri'=>$origin.'/protocol/openid-connect/certs','end_session_endpoint'=>$origin.'/protocol/openid-connect/logout'],JSON_THROW_ON_ERROR);
$jwk=json_encode(['keys'=>[['kid'=>'key-154','kty'=>'RSA','use'=>'sig','alg'=>'RS256','n'=>$b64($rsa['n']),'e'=>$b64($rsa['e'])]]],JSON_THROW_ON_ERROR);
$transport=static function(string $method,string $url,?string $body,array $headers) use($config,$jwk):array {return ['status'=>200,'body'=>str_ends_with($url,'/.well-known/openid-configuration')?$config:$jwk];};
$client=new OidcClient('cajeerengine-web','test-secret','https://cms.example.com/auth/oidc/callback',$transport);
$jwt=static function(array $claims,array $header=['alg'=>'RS256','kid'=>'key-154'])use($key,$b64):string {$head=$b64(json_encode($header,JSON_THROW_ON_ERROR));$payload=$b64(json_encode($claims,JSON_THROW_ON_ERROR));openssl_sign($head.'.'.$payload,$sig,$key,OPENSSL_ALGO_SHA256);return $head.'.'.$payload.'.'.$b64($sig);};
$event=['http://schemas.openid.net/event/backchannel-logout'=>(object)[]];
$base=['iss'=>$origin,'aud'=>'cajeerengine-web','iat'=>time(),'exp'=>time()+120,'jti'=>'unique-154','sid'=>'sso-session','sub'=>'linked-sub','events'=>$event];
$verified=$client->validateLogoutToken($jwt($base));
$ok($verified['sid']==='sso-session' && $verified['sub']==='linked-sub','signed logout token accepted');
$ok($client->validateLogoutToken($jwt(array_diff_key($base,['sid'=>1])))['sub']==='linked-sub','sub only supported');
$ok($client->validateLogoutToken($jwt(array_diff_key($base,['sub'=>1])))['sid']==='sso-session','sid only supported');
$url=$client->endSessionUrl('https://cms.example.com/auth/oidc/logout/complete');parse_str(parse_url($url,PHP_URL_QUERY),$params);
$ok(str_starts_with($url,$origin.'/protocol/openid-connect/logout?') && $params['client_id']==='cajeerengine-web' && $params['post_logout_redirect_uri']==='https://cms.example.com/auth/oidc/logout/complete','pinned RP-initiated logout URL');
$bad=['bad issuer'=>['iss'=>'https://attacker.test'],'bad audience'=>['aud'=>'other'],'expired'=>['exp'=>time()-300],'future iat'=>['iat'=>time()+400],'nonce forbidden'=>['nonce'=>'danger'],'null nonce forbidden'=>['nonce'=>null],'missing jti'=>['jti'=>null],'wrong events'=>['events'=>['other'=>(object)[]]],'extra event'=>['events'=>$event+['other'=>(object)[]]],'no identifiers'=>['sid'=>null,'sub'=>null],'invalid sid'=>['sid'=>''],'expired iat'=>['iat'=>time()-1000]];
foreach ($bad as $name=>$changes) {$test=array_replace($base,$changes);try {$client->validateLogoutToken($jwt($test));throw new RuntimeException('FAIL accepted '.$name);}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL'))throw $e;} $ok(true,'reject '.$name);}
$unsigned=$jwt($base);$unsigned=substr($unsigned,0,-5).'12345';try {$client->validateLogoutToken($unsigned);throw new RuntimeException('FAIL tampered accepted');}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL'))throw $e;}$ok(true,'reject tampered signature');
try {$client->endSessionUrl('https://attacker.test/auth/oidc/logout/complete');throw new RuntimeException('FAIL open redirect');}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'FAIL'))throw $e;}$ok(true,'reject post logout open redirect');
echo "Global logout RSA regression: $pass PASS\n";
