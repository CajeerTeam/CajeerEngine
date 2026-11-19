<?php

declare(strict_types=1);

/** Offline, executable Keycloak protocol / resource-server negative tests. */
require_once dirname(__DIR__).'/core/Identity/OidcClient.php';

use CajeerEngine\Identity\OidcClient;

$private = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA, 'private_key_bits'=>2048]);
if ($private === false) throw new RuntimeException('OpenSSL RSA 2048 required');
$key = openssl_pkey_get_details($private)['rsa'];
$b64 = static fn(string $text): string => rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
$issuer = 'https://id.skif4er.ru/realms/skif4er';
$endpoint = $issuer.'/protocol/openid-connect/';
$metadata = [
    'issuer'=>$issuer,
    'authorization_endpoint'=>$endpoint.'auth',
    'token_endpoint'=>$endpoint.'token',
    'jwks_uri'=>$endpoint.'certs',
    'introspection_endpoint'=>$endpoint.'token/introspect',
    'end_session_endpoint'=>$endpoint.'logout',
];
$jwks = ['keys'=>[['kid'=>'production-regression','kty'=>'RSA','use'=>'sig','alg'=>'RS256',
    'n'=>$b64($key['n']), 'e'=>$b64($key['e'])]]];
$introspection = [];
$transport = static function(string $method, string $url, ?string $body, array $headers)
    use (&$metadata, &$jwks, &$introspection, $issuer, $endpoint): array {
    if ($method==='GET' && $url===$issuer.'/.well-known/openid-configuration') $payload=$metadata;
    elseif ($method==='GET' && $url===$endpoint.'certs') $payload=$jwks;
    elseif ($method==='POST' && $url===$endpoint.'token/introspect') {
        if (!is_string($body) || !str_contains($body,'token_type_hint=access_token')) throw new RuntimeException('Token not introspected');
        $payload=$introspection;
    } else throw new RuntimeException('Unexpected OIDC request '.$url);
    return ['status'=>200, 'body'=>json_encode($payload, JSON_THROW_ON_ERROR)];
};
$client = new OidcClient('cajeerengine-web', 'fake-secret', 'https://cms.example.test/auth/oidc/callback', $transport);
$sign = static function(array $claims, array $header=['alg'=>'RS256','kid'=>'production-regression','typ'=>'JWT'])
    use ($b64, $private): string {
    $message = $b64(json_encode($header,JSON_THROW_ON_ERROR)).'.'.$b64(json_encode($claims,JSON_THROW_ON_ERROR));
    if (!openssl_sign($message,$signature,$private,OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signing failed');
    return $message.'.'.$b64($signature);
};
$count=0;
$pass=static function(bool $condition,string $name)use(&$count):void {
    if(!$condition) throw new RuntimeException('FAIL: '.$name);
    $count++;
    echo 'PASS '.$name.PHP_EOL;
};
$deny=static function(callable $run,string $name)use($pass):void {
    try{$run();} catch (RuntimeException $e){$pass(true,$name);return;}
    throw new RuntimeException('FAIL accepted: '.$name);
};

$probe = $client->probeProvider();
$pass($probe['usable_rs256_keys']===1 && $probe['introspection'] && $probe['logout']
    && $probe['issuer']===$issuer,'pinned discovery, RSA signing key and logout/introspection detected');
$jwks['keys']=[];
$deny(static fn()=>(new OidcClient('cajeerengine-web','secret','https://cms.example.test/auth/oidc/callback',$transport))->probeProvider(),
    'no valid provider signing keys');
$jwks['keys']=[['kid'=>'bad','kty'=>'RSA','alg'=>'RS256','n'=>'a','e'=>'AQAB']];
$deny(static fn()=>(new OidcClient('cajeerengine-web','secret','https://cms.example.test/auth/oidc/callback',$transport))->probeProvider(),
    'weak or invalid provider signing key');
$jwks['keys']=[['kid'=>'one-exponent','kty'=>'RSA','alg'=>'RS256','n'=>$b64($key['n']),'e'=>$b64("\x01")]];
$deny(static fn()=>(new OidcClient('cajeerengine-web','secret','https://cms.example.test/auth/oidc/callback',$transport))->probeProvider(),
    'insecure RSA public exponent');
$jwks['keys']=[['kid'=>'production-regression','kty'=>'RSA','use'=>'sig','alg'=>'RS256','n'=>$b64($key['n']),'e'=>$b64($key['e'])]];
$jwks['keys'][]=$jwks['keys'][0];
$deny(static fn()=>(new OidcClient('cajeerengine-web','secret','https://cms.example.test/auth/oidc/callback',$transport))->probeProvider(),
    'ambiguous duplicate signing key IDs');
array_pop($jwks['keys']);

$now=time();
$idClaims=['iss'=>$issuer,'aud'=>'cajeerengine-web','iat'=>$now-4,'exp'=>$now+120,
    'sub'=>'identity-subject','nonce'=>'fresh-nonce','typ'=>'ID'];
$pass($client->validateIdToken($sign($idClaims),'fresh-nonce')['sub']==='identity-subject',
    'valid signed Keycloak ID token remains compatible');
$deny(static fn()=>$client->validateIdToken($sign($idClaims,['alg'=>'RS256','kid'=>'production-regression','typ'=>'at+jwt']),'fresh-nonce'),
    'access token header substituted into login');
$deny(static fn()=>$client->validateIdToken($sign(array_replace($idClaims,['typ'=>'Bearer'])),'fresh-nonce'),
    'Bearer payload substituted into ID token');
$deny(static fn()=>$client->validateIdToken($sign(array_replace($idClaims,['typ'=>['malformed']])),'fresh-nonce'),
    'non-string ID token typ');

$access=['iss'=>$issuer,'aud'=>'cajeerengine-api','iat'=>$now-4,'exp'=>$now+120,
    'sub'=>'identity-subject','azp'=>'partner-frontend','typ'=>'Bearer','scope'=>'openid cajeer.api'];
$bearer=$sign($access);
$valid=['active'=>true,'sub'=>'identity-subject','client_id'=>'partner-frontend',
    'scope'=>'openid cajeer.api','exp'=>$now+120,'aud'=>'cajeerengine-api'];
$verify=static fn()=>$client->validateAccessToken($bearer,'cajeerengine-api',['partner-frontend'],'cajeer.api',true);
$introspection=$valid;
$pass($verify()['sub']==='identity-subject','introspection fully bound to valid signed access JWT');
foreach ([
    'active flag without token binding'=>['active'=>true],
    'missing sub'=>array_diff_key($valid,['sub'=>true]),
    'wrong subject'=>array_replace($valid,['sub'=>'other-sub']),
    'missing client'=>array_diff_key($valid,['client_id'=>true]),
    'wrong client'=>array_replace($valid,['client_id'=>'other-client']),
    'missing scope'=>array_diff_key($valid,['scope'=>true]),
    'missing expiry'=>array_diff_key($valid,['exp'=>true]),
    'expired introspection'=>array_replace($valid,['exp'=>$now-1]),
    'shortened introspection expiry'=>array_replace($valid,['exp'=>$now+90]),
    'unbounded expiry'=>array_replace($valid,['exp'=>$now+3600]),
    'wrong scope'=>array_replace($valid,['scope'=>'openid profile']),
] as $name=>$response) {
    $introspection=$response;
    $deny($verify,$name);
}
$introspection=$valid;
$pass($verify()['sub']==='identity-subject','successful authorization after transient bad introspection');
$deny(static fn()=>$client->endSessionUrl('https://cms.example.test:9443/auth/oidc/logout/complete'),
    'post logout redirect cannot change HTTPS port');
$pass(str_contains($client->endSessionUrl('https://cms.example.test/auth/oidc/logout/complete'),'post_logout_redirect_uri='),
    'same-origin Keycloak RP logout remains operational');

echo "SkiF4er ID Production protocol: {$count} PASS\n";
