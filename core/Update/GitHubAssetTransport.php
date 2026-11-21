<?php

declare(strict_types=1);

namespace CajeerEngine\Update;

use CajeerEngine\Security\PublicHttpClient;

/** Stream trusted-origin GitHub release assets without following arbitrary redirects. */
final class GitHubAssetTransport
{
    private const REDIRECT_HOSTS = ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'];

    /** @param resource $destination */
    public function download(string $url, mixed $destination, int $limit, int $timeoutSeconds = 300): int
    {
        if (!is_resource($destination) || $limit < 1 || $limit > 2147483648) {
            throw new \InvalidArgumentException('Invalid download destination or byte limit');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Secure release download requires ext-curl');
        }
        $timeoutSeconds = max(15, min(1800, $timeoutSeconds));
        $seen = [];
        for ($step = 0; $step <= 5; $step++) {
            self::validateUrl($url);
            if (isset($seen[$url])) throw new \RuntimeException('GitHub release redirect loop');
            $seen[$url] = true;
            [$host, $port, $ip] = PublicHttpClient::target($url);
            if (!ftruncate($destination, 0) || rewind($destination) === false) {
                throw new \RuntimeException('Cannot reset release download');
            }
            $size = 0;
            $location = null;
            $ch = curl_init($url);
            if ($ch === false) throw new \RuntimeException('Cannot initialize release download');
            try {
                $opts = [
                    CURLOPT_HTTPGET => true,
                    CURLOPT_HTTPHEADER => ['User-Agent: CajeerEngine-Secure-Release-Downloader', 'Accept: application/octet-stream', 'Accept-Encoding: identity'],
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_PROXY => '',
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_CONNECTTIMEOUT => min(15, $timeoutSeconds),
                    CURLOPT_TIMEOUT => $timeoutSeconds,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                        if (str_starts_with($header, 'HTTP/')) $location = null;
                        if (stripos($header, 'location:') === 0) $location = trim(substr($header, 9));
                        return strlen($header);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use ($destination, &$size, $limit): int {
                        $length = strlen($bytes);
                        if ($size > $limit - $length) return 0;
                        $written = fwrite($destination, $bytes);
                        if ($written !== $length) return 0;
                        $size += $length;
                        return $length;
                    },
                ];
                $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
                if (!curl_setopt_array($ch, $opts) || curl_exec($ch) === false) {
                    throw new \RuntimeException('GitHub release download failed, exceeded byte limit, or timed out');
                }
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            } finally {
                curl_close($ch);
            }
            if ($status === 200) {
                if ($size < 1 || !fflush($destination) || !fsync($destination)) {
                    throw new \RuntimeException('Empty or incomplete GitHub asset');
                }
                return $size;
            }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (!is_string($location) || $location === '' || $step === 5) {
                    throw new \RuntimeException('Invalid GitHub asset redirect');
                }
                self::validateUrl($location);
                $url = $location;
                continue;
            }
            throw new \RuntimeException('GitHub asset returned HTTP ' . $status);
        }
        throw new \RuntimeException('Too many GitHub asset redirects');
    }

    public static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !in_array(strtolower((string) ($parts['host'] ?? '')), self::REDIRECT_HOSTS, true)
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['fragment'])
            || isset($parts['port']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \RuntimeException('Untrusted GitHub asset URL');
        }
        if (strtolower((string) ($parts['host'] ?? '')) === 'github.com'
            && !str_starts_with(strtolower((string) ($parts['path'] ?? '')), '/cajeerteam/cajeerengine/releases/download/')) {
            throw new \RuntimeException('Untrusted GitHub repository asset path');
        }
    }
}
