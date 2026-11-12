<?php

declare(strict_types=1);

namespace CajeerEngine\Storage;

/**
 * Минимальный S3-compatible disk без внешнего SDK.
 * Поддерживает AWS Signature V4 и endpoint'ы S3/MinIO/VK Cloud/Yandex Object Storage.
 */
final readonly class S3CompatibleDisk implements StorageDiskInterface
{
    private string $endpoint;
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private bool $pathStyle;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->bucket = trim((string) ($config['bucket'] ?? ''), '/');
        $this->region = (string) ($config['region'] ?? 'auto');
        $this->accessKey = (string) ($config['access_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->pathStyle = filter_var($config['path_style'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($this->endpoint === '' || $this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
            throw new \InvalidArgumentException('S3 storage требует endpoint, bucket, access_key и secret_key.');
        }
    }

    public function put(string $path, string $contents): void
    {
        $this->request('PUT', $path, $contents, ['content-type' => 'application/octet-stream']);
    }

    public function get(string $path): string
    {
        $response = $this->request('GET', $path);
        return $response['body'];
    }

    public function exists(string $path): bool
    {
        try {
            $this->request('HEAD', $path);
            return true;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return false;
            }
            throw $e;
        }
    }

    public function delete(string $path): void
    {
        $this->request('DELETE', $path);
    }

    /** Short-lived SigV4 presigning, used only with a private bucket and configured endpoint. */
    public function presignedUrl(string $path,string $method='GET',int $ttl=300,bool $download=false): string
    {
        if(!in_array($method,['GET','PUT'],true)||$ttl<1||$ttl>900||!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)*$~D',$path)||preg_match('~(?:^|/)\.\.?(?:/|$)~',$path))throw new \InvalidArgumentException('Invalid S3 object or expiry');
        $url=$this->objectUrl($path);$parts=parse_url($url);
        if(!is_array($parts)||empty($parts['host'])||(isset($parts['user'])||isset($parts['pass']))||isset($parts['query'])||isset($parts['fragment']))throw new \RuntimeException('Invalid S3 endpoint');
        if(($parts['scheme']??'')!=='https'&&!(($parts['scheme']??'')==='http'&&in_array(getenv('APP_ENV'),['local','testing'],true)&&getenv('MEDIA_S3_ALLOW_HTTP')==='true'))throw new \RuntimeException('Media S3 requires HTTPS');
        $host=$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');$now=gmdate('Ymd\THis\Z');$date=substr($now,0,8);$scope=$date.'/'.$this->region.'/s3/aws4_request';
        $query=['X-Amz-Algorithm'=>'AWS4-HMAC-SHA256','X-Amz-Credential'=>$this->accessKey.'/'.$scope,'X-Amz-Date'=>$now,'X-Amz-Expires'=>(string)$ttl,'X-Amz-SignedHeaders'=>'host'];
        if($download&&$method==='GET'){$query['response-content-disposition']='attachment';$query['response-content-type']='application/octet-stream';}
        ksort($query,SORT_STRING);$canonicalQuery=http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $request=implode("\n",[$method,$this->canonicalUri($parts['path']??'/'),$canonicalQuery,'host:'.$host."\n",'host','UNSIGNED-PAYLOAD']);
        $signature=hash_hmac('sha256',"AWS4-HMAC-SHA256\n".$now."\n".$scope."\n".hash('sha256',$request),$this->signingKey($date));
        return $url.'?'.$canonicalQuery.'&X-Amz-Signature='.$signature;
    }
    public function putFile(string $key,string $localPath): void
    {
        if(!function_exists('curl_init'))throw new \RuntimeException('ext-curl required for streaming S3 replicas');
        $in=fopen($localPath,'rb');if(!$in)throw new \RuntimeException('Cannot open media for S3');
        $curl=curl_init($this->presignedUrl($key,'PUT',900));
        try{curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$in,CURLOPT_INFILESIZE=>filesize($localPath),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>300,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Content-Type: application/octet-stream','Expect:']]);
            $result=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if($result===false||$status<200||$status>=300)throw new \RuntimeException('S3 upload failed: HTTP '.$status);
        }finally{curl_close($curl);fclose($in);}
    }
    public function fileChecksum(string $key): string
    {
        if(!function_exists('curl_init'))throw new \RuntimeException('ext-curl required for streaming S3 replicas');
        $ctx=hash_init('sha256');$curl=curl_init($this->presignedUrl($key,'GET',900));$bytes=0;
        try{curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>300,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,string $data)use($ctx,&$bytes){$bytes+=strlen($data);if($bytes>104857600)return 0;hash_update($ctx,$data);return strlen($data);}]);
            $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if($ok===false||$status!==200)throw new \RuntimeException('S3 verification failed: HTTP '.$status);return hash_final($ctx);
        }finally{curl_close($curl);}
    }

    /** @return array{status:int,headers:list<string>,body:string} */
    private function request(string $method, string $path, string $body = '', array $extraHeaders = []): array
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $payloadHash = hash('sha256', $body);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $url = $this->objectUrl($path);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \RuntimeException('Некорректный S3 endpoint: ' . $this->endpoint);
        }

        $host = (string) $parts['host'];
        if (isset($parts['port'])) {
            $host .= ':' . (string) $parts['port'];
        }
        $canonicalUri = $this->canonicalUri((string) ($parts['path'] ?? '/'));
        $headers = array_change_key_case($extraHeaders, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $now;

        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string) $value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $credentialScope = $date . '/' . $this->region . '/s3/aws4_request';
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $method === 'GET' || $method === 'HEAD' ? '' : $body,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => (float) ($this->config['timeout'] ?? 15),
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        $status = $this->statusCode($responseHeaders);
        if ($result === false) {
            throw new \RuntimeException('S3 request failed без HTTP-ответа: ' . $method . ' ' . $url);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('S3 request failed: HTTP ' . $status . ' ' . $method . ' ' . $url . ' ' . trim($result));
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $result];
    }

    private function objectUrl(string $path): string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        if ($this->pathStyle) {
            return $this->endpoint . '/' . rawurlencode($this->bucket) . '/' . $encodedPath;
        }

        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint;
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $base = $scheme . '://' . rawurlencode($this->bucket) . '.' . $host . ($port ? ':' . $port : '');

        return $base . '/' . $encodedPath;
    }

    private function canonicalUri(string $path): string
    {
        $segments = array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $path));
        return implode('/', $segments) ?: '/';
    }

    private function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** @param list<string> $headers */
    private function statusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $parts = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
