<?php

declare(strict_types=1);
namespace CajeerEngine\ExtensionSdk;

/** REST client for every generated OpenAPI operation. Base URL includes /api/v1. */
final readonly class RestClient
{
    public function __construct(private string $baseUrl, private ?string $token = null)
    {
        $url = parse_url($baseUrl);
        if (!is_array($url) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl)
            || (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && in_array($url['host'] ?? '', ['127.0.0.1','localhost','[::1]'], true))) || empty($url['host'])) throw new \InvalidArgumentException('HTTPS API base URL required');
        if ($token !== null && preg_match('/[\r\n]/', $token)) throw new \InvalidArgumentException('Invalid token');
    }

    public function call(string $operation, array $path = [], array $query = [], mixed $body = null): mixed
    {
        $spec = Operations::ALL[$operation] ?? throw new \InvalidArgumentException('Unknown API operation');
        $uri = preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($path): string {
            if (!isset($path[$m[1]]) || !is_scalar($path[$m[1]]) || (string)$path[$m[1]] === '') throw new \InvalidArgumentException('Missing path parameter: '.$m[1]);
            return rawurlencode((string)$path[$m[1]]);
        }, $spec['path']);
        if ($spec['bodyRequired'] && $body === null) throw new \InvalidArgumentException('Request body required');
        if (!function_exists('curl_init')) throw new \RuntimeException('ext-curl required');
        $url = rtrim($this->baseUrl, '/').$uri.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $headers = ['Accept: application/json'];
        if ($this->token !== null) $headers[] = 'Authorization: Bearer '.$this->token;
        $ch = curl_init($url);
        $response = '';
        $options = [CURLOPT_CUSTOMREQUEST=>$spec['method'], CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($ch,string $chunk) use (&$response): int { if (strlen($response)+strlen($chunk)>33554432) return 0; $response.=$chunk; return strlen($chunk); }];
        if ($body !== null) { $headers[]='Content-Type: application/json'; $options[CURLOPT_POSTFIELDS]=json_encode($body, JSON_THROW_ON_ERROR); }
        $options[CURLOPT_HTTPHEADER]=$headers;
        try {
            curl_setopt_array($ch,$options);
            if (curl_exec($ch)===false) throw new \RuntimeException('API transport failed');
            $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if ($status<200 || $status>=300) throw new \RuntimeException('API HTTP '.$status, $status);
            return $response === '' ? null : json_decode($response,true,512,JSON_THROW_ON_ERROR);
        } finally { curl_close($ch); }
    }
}
