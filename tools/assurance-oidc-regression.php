<?php

declare(strict_types=1);

/** Pure-PHP protocol regression without Composer or a live Keycloak account. */
require_once dirname(__DIR__).'/core/Identity/OidcClient.php';
use CajeerEngine\Identity\OidcClient;
$issuer='https://id.skif4er.ru/realms/skif4er';
$base=$issuer.'/protocol/openid-connect';
$transport=static function(string $method,string $url,?string $body,array $headers)use($issuer,$base):array {
    if ($url === $issuer.'/.well-known/openid-configuration') {
        return ['status'=>200, 'body'=>json_encode(['issuer'=>$issuer,'authorization_endpoint'=>$base.'/auth','token_endpoint'=>$base.'/token','jwks_uri'=>$base.'/certs'],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected OIDC request '.$url);
};
$client=new OidcClient('cajeerengine-web','secret','https://cms.example.test/auth/oidc/callback',$transport);
$normal=$client->begin();$step=$client->begin(true);
$check=static function(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";};
$ordinary=[];$relogin=[];
parse_str(parse_url($normal['url'],PHP_URL_QUERY),$ordinary);parse_str(parse_url($step['url'],PHP_URL_QUERY),$relogin);
$check(!isset($ordinary['max_age']) && !isset($ordinary['prompt']),'regular SSO entry keeps existing login flow');
$check(array_key_exists('max_age',$relogin) && (string)$relogin['max_age']==='0' && $relogin['prompt']==='login','step-up forces interactive IdP reauthentication');
$check($relogin['response_type']==='code' && $relogin['code_challenge_method']==='S256','step-up retains authorization code and PKCE S256');
$check($step['state']!==$normal['state'] && $step['nonce']!==$normal['nonce'] && $step['verifier']!==$normal['verifier'],'new state, nonce and PKCE per operation');
$check($relogin['redirect_uri']==='https://cms.example.test/auth/oidc/callback','callback remains pinned HTTPS');
echo "OIDC step-up protocol regression PASS\n";
