<?php

declare(strict_types=1);
/** Real RS256 access-token profile + live-introspection protocol tests. No external server. */
require_once dirname(__DIR__).'/core/Identity/OidcClient.php';
use CajeerEngine\Identity\OidcClient;

$key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>2048]);
if (!$key) throw new RuntimeException('RSA unavailable');
$rsa=openssl_pkey_get_details($key)['rsa'];
$b64=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$origin='https://id.skif4er.ru/realms/skif4er';
$metadata=json_encode(['issuer'=>$origin,'authorization_endpoint'=>$origin.'/protocol/openid-connect/auth','token_endpoint'=>$origin.'/protocol/openid-connect/token','jwks_uri'=>$origin.'/protocol/openid-connect/certs','introspection_endpoint'=>$origin.'/protocol/openid-connect/token/introspect'],JSON_THROW_ON_ERROR);
$jwks=json_encode(['keys'=>[['kid'=>'test-access','kty'=>'RSA','alg'=>'RS256','use'=>'sig','n'=>$b64($rsa['n']),'e'=>$b64($rsa['e'])]]],JSON_THROW_ON_ERROR);
$claims=['iss'=>$origin,'aud'=>['cajeerengine-api','account'],'sub'=>'linked-user','iat'=>time()-5,'exp'=>time()+300,'nbf'=>time()-5,'jti'=>'access-1','azp'=>'partner-frontend','typ'=>'Bearer','scope'=>'openid profile cajeer.api'];
$sign=static function(array $payload,array $header=['kid'=>'test-access','alg'=>'RS256','typ'=>'JWT'])use($key,$b64):string {
    $text=$b64(json_encode($header,JSON_THROW_ON_ERROR)).'.'.$b64(json_encode($payload,JSON_THROW_ON_ERROR));
    openssl_sign($text,$signature,$key,OPENSSL_ALGO_SHA256);
    return $text.'.'.$b64($signature);
};
$active=true; $transportFault=false; $introspectionSubject='linked-user'; $lastBody=''; $lastHeaders=[]; $introspectionAudience='cajeerengine-api';
$transport=static function(string $method,string $url,?string $body,array $headers)use($metadata,$jwks,&$active,&$transportFault,&$introspectionSubject,&$lastBody,&$lastHeaders,&$introspectionAudience,$claims):array {
    if(str_ends_with($url,'/.well-known/openid-configuration'))return ['status'=>200,'body'=>$metadata];
    if(str_ends_with($url,'/certs'))return ['status'=>200,'body'=>$jwks];
    if(str_ends_with($url,'/token/introspect')) {
        $lastBody=(string)$body;$lastHeaders=$headers;
        if($transportFault) return ['status'=>503,'body'=>''];
        return ['status'=>200,'body'=>json_encode(['active'=>$active,'sub'=>$introspectionSubject,'aud'=>$introspectionAudience,'client_id'=>'partner-frontend','scope'=>'openid cajeer.api','exp'=>$claims['exp']],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected endpoint: '.$url);
};
$client=new OidcClient('cajeerengine-web','private-client-secret','https://cms.example.test/auth/oidc/callback',$transport);
$checkCount=0;
$ok=static function(bool $condition,string $label)use(&$checkCount):void{if(!$condition)throw new RuntimeException('FAIL '.$label);$checkCount++;echo 'PASS '.$label.PHP_EOL;};
$verify=static fn(string $jwt,bool $online=true)=>$client->validateAccessToken($jwt,'cajeerengine-api',['partner-frontend'],'cajeer.api',$online);
$rejected=static function(string $jwt,string $reason,bool $online=true)use($verify,$ok):void{
    try{$verify($jwt,$online); throw new RuntimeException('accepted '.$reason);}catch(RuntimeException $e){if($e->getMessage()==='accepted '.$reason)throw $e;}
    $ok(true,'rejected '.$reason);
};
$good=$sign($claims);
$ok($verify($good)['sub']==='linked-user','real signed Keycloak Bearer access JWT');
$ok(str_contains($lastBody,'token_type_hint=access_token'),'live introspection requests real access token');
$ok(in_array('Authorization: Basic '.base64_encode('cajeerengine-web:private-client-secret'),$lastHeaders,true),'introspection confidential client authentication');
$atClaims=$claims; unset($atClaims['typ']);
$ok($verify($sign($atClaims,['kid'=>'test-access','alg'=>'RS256','typ'=>'at+jwt']))['sub']==='linked-user','RFC 9068 typed access token accepted');
$id=$claims; unset($id['typ']);$id['nonce']='nonce';
$rejected($sign($id),'ID Token treated as API token');
$changes=[
    'wrong audience'=>['aud'=>['account']],
    'wrong issuer'=>['iss'=>'https://other.example'],
    'unauthorized OAuth client'=>['azp'=>'other-client'],
    'wrong client_id'=>['client_id'=>'forged-client'],
    'missing permission scope'=>['scope'=>'openid profile'],
    'expired'=>['exp'=>time()-1],
    'future issued'=>['iat'=>time()+200],
    'future not-before'=>['nbf'=>time()+200],
    'invalid subject'=>['sub'=>''],
    'logout token in access channel'=>['events'=>['http://schemas.openid.net/event/backchannel-logout'=>[]]],
    'nonce in bearer access token'=>['nonce'=>'unexpected'],
    'excessive token lifetime'=>['exp'=>time()+100000],
];
foreach($changes as $reason=>$change)$rejected($sign(array_replace($claims,$change)),$reason,false);
$rejected($sign($claims,['kid'=>'test-access','alg'=>'none','typ'=>'JWT']),'unsigned algorithm',false);
$rejected($sign($claims,['kid'=>'test-access','alg'=>'RS256','typ'=>'JWT','jku'=>'https://evil.example/key']),'untrusted JKU',false);
$rejected($sign($claims,['kid'=>'wrong-key','alg'=>'RS256','typ'=>'JWT']),'unknown signing key',false);
$rejected($sign($claims,['kid'=>'test-access','alg'=>'RS256','typ'=>'ID']),'incorrect token header',false);
$rejected(substr($good,0,-6).'FAILED','tampered signature',false);
$active=false; $rejected($good,'revoked token'); $active=true;
$transportFault=true; $rejected($good,'IdP temporarily down'); $transportFault=false;
$introspectionSubject='another-user'; $rejected($good,'introspection subject mismatch'); $introspectionSubject='linked-user';
$introspectionAudience='other-api'; $rejected($good,'introspection audience mismatch'); $introspectionAudience='cajeerengine-api';
$ok($verify($good)['sub']==='linked-user','introspection recovery after outage');
echo "OIDC API access-token regression: $checkCount PASS\n";
