<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

/** SkiF4er ID confidential Authorization Code + PKCE client. Never sends tokens to the browser. */
final class OidcClient
{
    private const ISSUER = 'https://id.skif4er.ru/realms/skif4er';
    private ?array $discovery = null;
    /** @var null|\Closure(string,string,?string,array):array{status:int,body:string} */
    private readonly ?\Closure $transport;

    public function __construct(private readonly string $clientId, #[\SensitiveParameter] private readonly string $clientSecret, private readonly string $callbackUrl, ?\Closure $transport = null)
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,100}$/D', $clientId) || $clientSecret === '') {
            throw new \RuntimeException('OIDC confidential client credentials missing.');
        }
        $uri = parse_url($callbackUrl);
        if (!is_array($uri) || ($uri['scheme'] ?? '') !== 'https' || empty($uri['host']) || isset($uri['user']) || isset($uri['pass']) || isset($uri['fragment']) || !str_ends_with((string) ($uri['path'] ?? ''), '/auth/oidc/callback')) {
            throw new \RuntimeException('OIDC callback must be a registered absolute HTTPS URL.');
        }
        $this->transport = $transport;
    }

    public function issuer(): string { return self::ISSUER; }

    /** @return array{url:string,state:string,nonce:string,verifier:string} */
    public function begin(): array
    {
        $state = self::base64Url(random_bytes(32));
        $nonce = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(32));
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        $url = $this->configuration()['authorization_endpoint'] . '?' . http_build_query([
            'response_type'=>'code', 'client_id'=>$this->clientId, 'redirect_uri'=>$this->callbackUrl,
            'scope'=>'openid profile email', 'state'=>$state, 'nonce'=>$nonce,
            'code_challenge'=>$challenge, 'code_challenge_method'=>'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return compact('url', 'state', 'nonce', 'verifier');
    }

    /** @return array<string,mixed> validated identity claims */
    public function finish(string $code, string $verifier, string $nonce): array
    {
        if ($code === '' || strlen($code) > 4096 || !preg_match('/^[A-Za-z0-9._~+\/-]+$/D', $code) || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $verifier)) {
            throw new \RuntimeException('Invalid OIDC authorization code or PKCE verifier.');
        }
        $body = http_build_query([
            'grant_type'=>'authorization_code', 'code'=>$code, 'redirect_uri'=>$this->callbackUrl,
            'client_id'=>$this->clientId, 'client_secret'=>$this->clientSecret, 'code_verifier'=>$verifier,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('POST', $this->configuration()['token_endpoint'], $body, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        if ($response['status'] !== 200) throw new \RuntimeException('OIDC token exchange failed.');
        $tokens = $this->decodeJson($response['body']);
        if (!isset($tokens['id_token']) || !is_string($tokens['id_token'])) throw new \RuntimeException('OIDC id_token missing.');
        return $this->validateIdToken($tokens['id_token'], $nonce);
    }

    /** @return array<string,mixed> */
    public function validateIdToken(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 16384) throw new \RuntimeException('Malformed OIDC JWT.');
        [$h, $p, $signature] = $parts;
        $header = $this->decodeJson(self::decodeBase64Url($h));
        $claims = $this->decodeJson(self::decodeBase64Url($p));
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '' || isset($header['jku']) || isset($header['x5u']) || isset($header['crit'])) {
            throw new \RuntimeException('Unsupported OIDC JWT header.');
        }
        $jwks = $this->decodeJson($this->requestOk('GET', $this->configuration()['jwks_uri']));
        $key = null;
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $header['kid'] && ($jwk['kty'] ?? null) === 'RSA' && ($jwk['use'] ?? 'sig') === 'sig' && in_array(($jwk['alg'] ?? 'RS256'), ['RS256'], true)) { $key = $jwk; break; }
        }
        if ($key === null) throw new \RuntimeException('Unknown OIDC signing key.');
        $pem = self::rsaPem((string) ($key['n'] ?? ''), (string) ($key['e'] ?? ''));
        if (openssl_verify($h.'.'.$p, self::decodeBase64Url($signature), $pem, OPENSSL_ALGO_SHA256) !== 1) throw new \RuntimeException('OIDC signature invalid.');
        $now = time(); $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (($claims['iss'] ?? null) !== self::ISSUER || !in_array($this->clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $this->clientId)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 30
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 60))
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || strlen($claims['sub']) < 1 || strlen($claims['sub']) > 255) {
            throw new \RuntimeException('OIDC identity claims rejected.');
        }
        return $claims;
    }

    /** @return array{authorization_endpoint:string,token_endpoint:string,jwks_uri:string} */
    private function configuration(): array
    {
        if ($this->discovery !== null) return $this->discovery;
        $document = $this->decodeJson($this->requestOk('GET', self::ISSUER . '/.well-known/openid-configuration'));
        if (($document['issuer'] ?? '') !== self::ISSUER) throw new \RuntimeException('OIDC issuer mismatch.');
        $result = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $name) {
            $url = $document[$name] ?? null;
            if (!is_string($url) || !self::pinnedUrl($url)) throw new \RuntimeException('OIDC discovery contains an unsafe endpoint.');
            $result[$name] = $url;
        }
        return $this->discovery = $result;
    }

    private static function pinnedUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'id.skif4er.ru'
            && !isset($parts['port']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && str_starts_with((string) ($parts['path'] ?? ''), '/realms/skif4er/protocol/openid-connect/')
            && !isset($parts['query']);
    }

    private function requestOk(string $method, string $url): string
    {
        $response = $this->request($method, $url, null, ['Accept: application/json']);
        if ($response['status'] !== 200) throw new \RuntimeException('OIDC endpoint unavailable.');
        return $response['body'];
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        if (!($url === self::ISSUER . '/.well-known/openid-configuration' || self::pinnedUrl($url))) throw new \RuntimeException('Unsafe OIDC URL.');
        if ($this->transport !== null) return ($this->transport)($method, $url, $body, $headers);
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension required for SkiF4er ID.');
        $handle = curl_init($url);
        if ($handle === false) throw new \RuntimeException('Cannot initialize OIDC HTTP transport.');
        try {
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>10,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>'CajeerEngine-OIDC/1.5.1', CURLOPT_MAXREDIRS=>0]);
            if ($method === 'POST') curl_setopt_array($handle, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body]);
            $content = curl_exec($handle);
            if ($content === false || strlen($content) > 262144) throw new \RuntimeException('OIDC network request failed or response too large.');
            return ['status'=>(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body'=>$content];
        } finally { curl_close($handle); }
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        if (strlen($json) > 262144) throw new \RuntimeException('OIDC JSON too large.');
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new \RuntimeException('OIDC JSON object required.');
        return $data;
    }

    private static function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

    private static function decodeBase64Url(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value) || strlen($value) % 4 === 1) throw new \RuntimeException('Malformed OIDC base64url.');
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new \RuntimeException('Invalid OIDC base64url.');
        return $decoded;
    }

    private static function asn1(string $data): string
    {
        $length = strlen($data);
        return $length < 128 ? chr($length).$data : chr(0x80 | strlen(ltrim(pack('N', $length), "\0"))).ltrim(pack('N', $length), "\0").$data;
    }

    private static function rsaPem(string $n, string $e): string
    {
        $modulus = self::decodeBase64Url($n); $exponent = self::decodeBase64Url($e);
        if (strlen($modulus) < 256 || strlen($modulus) > 1024 || strlen($exponent) < 1 || strlen($exponent) > 8) throw new \RuntimeException('Invalid OIDC RSA key size.');
        $positive = static fn (string $v): string => "\x02" . self::asn1((ord($v[0]) & 0x80) ? "\x00" . $v : $v);
        $rsa = "\x30" . self::asn1($positive($modulus) . $positive($exponent));
        $bitString = "\x03" . self::asn1("\x00".$rsa);
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $spki = "\x30" . self::asn1($algorithm.$bitString);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
