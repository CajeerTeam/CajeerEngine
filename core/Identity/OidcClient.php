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
    public function begin(bool $stepUp = false): array
    {
        $state = self::base64Url(random_bytes(32));
        $nonce = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(32));
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        $url = $this->configuration()['authorization_endpoint'] . '?' . http_build_query([
            'response_type'=>'code', 'client_id'=>$this->clientId, 'redirect_uri'=>$this->callbackUrl,
            'scope'=>'openid profile email', 'state'=>$state, 'nonce'=>$nonce,
            'code_challenge'=>$challenge, 'code_challenge_method'=>'S256',
            ...($stepUp ? ['max_age'=>0, 'prompt'=>'login'] : []),
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
        // Distinguish ID tokens from bearer access tokens even if the latter
        // share the same issuer, audience, signing key and happen to carry a nonce.
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new \RuntimeException('Malformed OIDC ID token.');
        $header = $this->decodeJson(self::decodeBase64Url($parts[0]));
        $headerType = strtolower((string)($header['typ'] ?? 'jwt'));
        if (!in_array($headerType, ['jwt', 'id+jwt', 'application/id+jwt'], true)) {
            throw new \RuntimeException('An access token cannot be used as an ID token.');
        }
        $claims = $this->verifiedJwt($jwt);
        if (isset($claims['typ']) && (!is_string($claims['typ']) || !in_array(strtolower($claims['typ']), ['id', 'id_token'], true))) {
            throw new \RuntimeException('OIDC ID token type mismatch.');
        }
        $now = time(); $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        if (($claims['iss'] ?? null) !== self::ISSUER || !in_array($this->clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $this->clientId)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 30
            || !is_int($claims['iat'] ?? null) || $claims['iat'] <= 0 || $claims['iat'] > $now + 60
            || $claims['exp'] <= $claims['iat'] || $claims['exp'] > $claims['iat'] + 86400
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 60))
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || strlen($claims['sub']) < 1 || strlen($claims['sub']) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $claims['sub'])) {
            throw new \RuntimeException('OIDC identity claims rejected.');
        }
        return $claims;
    }

    /**
     * Validate an access token for *this API*, never an ID token intended for login.
     * Keycloak's legacy JWT header uses typ=JWT with typ=Bearer in the payload;
     * RFC 9068 tokens use the explicitly typed at+jwt header instead.
     *
     * @param list<string> $allowedClients OAuth clients permitted to call this resource server
     * @return array<string,mixed> verified access claims (never log or serialize raw token)
     */
    public function validateAccessToken(string $jwt, string $audience, array $allowedClients, string $requiredScope, bool $onlineIntrospection = true): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 16384) throw new \RuntimeException('Malformed access token.');
        $header = $this->decodeJson(self::decodeBase64Url($parts[0]));
        $typ = strtolower((string)($header['typ'] ?? ''));
        if (!in_array($typ, ['at+jwt', 'application/at+jwt', 'jwt'], true)) {
            throw new \RuntimeException('Incorrect access token type.');
        }
        $claims = $this->verifiedJwt($jwt);
        // Generic Keycloak JWTs MUST carry their own explicit Bearer type.
        if (($typ === 'jwt' && ($claims['typ'] ?? null) !== 'Bearer')
            || (isset($claims['typ']) && $claims['typ'] !== 'Bearer')) {
            throw new \RuntimeException('An ID token cannot authorize API access.');
        }
        $now = time();
        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) && array_is_list($aud) ? $aud : []);
        $authorizedClient = $claims['azp'] ?? $claims['client_id'] ?? null;
        if (($claims['iss'] ?? null) !== self::ISSUER
            || !is_string($authorizedClient) || !in_array($authorizedClient, $allowedClients, true)
            || (isset($claims['client_id'], $claims['azp']) && $claims['client_id'] !== $claims['azp'])
            || !in_array($audience, $audiences, true)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] <= 0
            || $claims['exp'] > $claims['iat'] + 86400
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 30))
            || !is_string($claims['sub'] ?? null) || strlen($claims['sub']) < 1 || strlen($claims['sub']) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $claims['sub'])
            || !is_string($claims['scope'] ?? null)
            || !in_array($requiredScope, preg_split('/\s+/', trim($claims['scope'])) ?: [], true)
            || isset($claims['nonce']) || isset($claims['events'])) {
            throw new \RuntimeException('Access token claims rejected.');
        }
        if ($onlineIntrospection) {
            $this->confirmActiveAccessToken($jwt, $claims, $audience, $requiredScope);
        }
        return $claims;
    }

    /** Check current server-side revocation state. Failure or outage must deny access. */
    private function confirmActiveAccessToken(string $jwt, array $claims, string $audience, string $scope): void
    {
        $endpoint = $this->configuration()['introspection_endpoint'] ?? null;
        if (!is_string($endpoint)) throw new \RuntimeException('Token introspection unavailable.');
        $response = $this->request('POST', $endpoint, http_build_query([
            'token'=>$jwt, 'token_type_hint'=>'access_token',
        ], '', '&', PHP_QUERY_RFC3986), [
            'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
            'Authorization: Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
        ]);
        if ($response['status'] !== 200) throw new \RuntimeException('Token introspection failed.');
        $current = $this->decodeJson($response['body']);
        $aud = $current['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) && array_is_list($aud) ? $aud : []);
        // A bare {"active":true} does not bind the introspection response to
        // the signed access token being used. Require identity, client, scope
        // and expiry in the Keycloak response (and verify optional claims).
        if (($current['active'] ?? false) !== true
            || !is_string($current['sub'] ?? null) || !hash_equals((string)$claims['sub'], $current['sub'])
            || !is_string($current['client_id'] ?? null)
            || $current['client_id'] !== ($claims['azp'] ?? $claims['client_id'] ?? null)
            || !is_string($current['scope'] ?? null)
            || !in_array($scope, preg_split('/\s+/', trim($current['scope'])) ?: [], true)
            || !is_int($current['exp'] ?? null) || $current['exp'] <= time()
            || $current['exp'] !== $claims['exp']
            || (isset($current['iss']) && $current['iss'] !== self::ISSUER)
            || (isset($current['aud']) && !in_array($audience, $audiences, true))) {
            throw new \RuntimeException('Access token is inactive or mismatched.');
        }
    }

    /** Verified, signed JWT claims. Signature validation is shared by ID and logout tokens. */
    private function verifiedJwt(string $jwt): array
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
        return $claims;
    }

    /** Validate signed OIDC Back-Channel Logout 1.0 token. No nonce is permitted. */
    public function validateLogoutToken(string $jwt): array
    {
        $claims = $this->verifiedJwt($jwt);
        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? $aud : []);
        $now = time();
        $event = $claims['events']['http://schemas.openid.net/event/backchannel-logout'] ?? null;
        $sub = $claims['sub'] ?? null;
        $sid = $claims['sid'] ?? null;
        $jti = $claims['jti'] ?? null;
        if (($claims['iss'] ?? null) !== self::ISSUER
            || !in_array($this->clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $this->clientId)
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] < $now - 600
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 30 || $claims['exp'] > $claims['iat'] + 900
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 60))
            || array_key_exists('nonce', $claims)
            || !is_array($claims['events'] ?? null) || array_is_list($claims['events'])
            || count($claims['events']) !== 1 || !is_array($event) || $event !== []
            || !is_string($jti) || $jti === '' || strlen($jti) > 255
            || ($sub === null && $sid === null)
            || ($sub !== null && (!is_string($sub) || $sub === '' || strlen($sub) > 255 || preg_match('/[\x00-\x1f\x7f]/', $sub)))
            || ($sid !== null && (!is_string($sid) || $sid === '' || strlen($sid) > 512 || preg_match('/[\x00-\x1f\x7f]/', $sid)))
        ) throw new \RuntimeException('OIDC logout token rejected.');
        return ['iss'=>self::ISSUER, 'sub'=>$sub, 'sid'=>$sid, 'jti'=>$jti, 'exp'=>$claims['exp']];
    }

    /** Pinned Keycloak RP-Initiated Logout endpoint with an exact registered HTTPS callback. */
    public function endSessionUrl(string $postLogoutUrl): string
    {
        $parts = parse_url($postLogoutUrl);
        $callback = parse_url($this->callbackUrl);
        if (!is_array($parts) || !is_array($callback) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== ($callback['host'] ?? null)
            || ($parts['port'] ?? 443) !== ($callback['port'] ?? 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])
            || ($parts['path'] ?? '') !== '/auth/oidc/logout/complete') throw new \RuntimeException('Invalid post logout redirect URI.');
        $url = $this->configuration()['end_session_endpoint'] ?? null;
        if (!is_string($url)) throw new \RuntimeException('OIDC provider does not offer RP-initiated logout.');
        return $url.'?'.http_build_query(['client_id'=>$this->clientId, 'post_logout_redirect_uri'=>$postLogoutUrl], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Read-only production IdP acceptance probe. Uses the same pinned discovery
     * and JWKS transport as token validation, and never requests a login code.
     * @return array{issuer:string,usable_rs256_keys:int,introspection:bool,logout:bool}
     */
    public function probeProvider(): array
    {
        $configuration = $this->configuration();
        $jwks = $this->decodeJson($this->requestOk('GET', $configuration['jwks_uri']));
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 128) {
            throw new \RuntimeException('OIDC JWKS document invalid.');
        }
        $usable = 0;
        $kids = [];
        foreach ($keys as $key) {
            if (!is_array($key) || !is_string($key['kid'] ?? null) || $key['kid'] === '' || strlen($key['kid']) > 256) continue;
            if (($key['kty'] ?? null) !== 'RSA' || ($key['use'] ?? 'sig') !== 'sig' || ($key['alg'] ?? 'RS256') !== 'RS256') continue;
            if (isset($kids[$key['kid']])) throw new \RuntimeException('Duplicate OIDC signing key ID.');
            $kids[$key['kid']] = true;
            try { $pem = self::rsaPem((string)($key['n'] ?? ''), (string)($key['e'] ?? ''));
                if (openssl_pkey_get_public($pem) !== false) $usable++; }
            catch (\RuntimeException) { /* Invalid keys are never trusted. */ }
        }
        if ($usable === 0) throw new \RuntimeException('No usable OIDC RS256 signing key.');
        return ['issuer'=>self::ISSUER, 'usable_rs256_keys'=>$usable,
            'introspection'=>isset($configuration['introspection_endpoint']),
            'logout'=>isset($configuration['end_session_endpoint'])];
    }

    /** @return array{authorization_endpoint:string,token_endpoint:string,jwks_uri:string,end_session_endpoint?:string,introspection_endpoint?:string} */
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
        if (isset($document['end_session_endpoint'])) {
            if (!is_string($document['end_session_endpoint']) || !self::pinnedUrl($document['end_session_endpoint'])) throw new \RuntimeException('Unsafe OIDC logout endpoint.');
            $result['end_session_endpoint'] = $document['end_session_endpoint'];
        }
        if (isset($document['introspection_endpoint'])) {
            if (!is_string($document['introspection_endpoint']) || !self::pinnedUrl($document['introspection_endpoint'])) throw new \RuntimeException('Unsafe OIDC introspection endpoint.');
            $result['introspection_endpoint'] = $document['introspection_endpoint'];
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
        // Retrying read-only discovery/JWKS is safe; token exchange and introspection
        // are deliberately never retried (authorization codes are single-use).
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $this->request($method, $url, null, ['Accept: application/json']);
                if ($response['status'] === 200) return $response['body'];
                if (!in_array($response['status'], [408, 425, 429, 500, 502, 503, 504], true)) break;
            } catch (\RuntimeException) {
                if ($attempt === 1) throw new \RuntimeException('OIDC endpoint unavailable.');
            }
            if ($attempt === 0) usleep(random_int(80000, 150000));
        }
        throw new \RuntimeException('OIDC endpoint unavailable.');
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
            $content = '';
            curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>10,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>'CajeerEngine-OIDC', CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROXY=>'', CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$content): int {
                    if (strlen($content) + strlen($chunk) > 262144) return 0;
                    $content .= $chunk;
                    return strlen($chunk);
                }]);
            if ($method === 'POST') curl_setopt_array($handle, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body]);
            if (curl_exec($handle) === false) throw new \RuntimeException('OIDC network request failed or response too large.');
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
        // Reject weak keys and pathological exponents even when OpenSSL can
        // parse their ASN.1. The modulus must have at least 2048 effective bits.
        if (strlen($modulus) < 256 || strlen($modulus) > 1024 || (ord($modulus[0]) & 0x80) === 0
            || strlen($exponent) < 1 || strlen($exponent) > 4 || ord($exponent[0]) === 0) {
            throw new \RuntimeException('Invalid OIDC RSA key size.');
        }
        $publicExponent = 0;
        foreach (str_split($exponent) as $byte) $publicExponent = ($publicExponent << 8) | ord($byte);
        if ($publicExponent < 3 || ($publicExponent & 1) === 0) throw new \RuntimeException('Invalid OIDC RSA public exponent.');
        $positive = static fn (string $v): string => "\x02" . self::asn1((ord($v[0]) & 0x80) ? "\x00" . $v : $v);
        $rsa = "\x30" . self::asn1($positive($modulus) . $positive($exponent));
        $bitString = "\x03" . self::asn1("\x00".$rsa);
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $spki = "\x30" . self::asn1($algorithm.$bitString);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
