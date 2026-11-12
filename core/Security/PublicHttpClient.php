<?php

declare(strict_types=1);
namespace CajeerEngine\Security;

/** Public-only outbound transport. DNS is validated and the selected address is pinned to the connection. */
final class PublicHttpClient
{
    public static function isPublicIp(string $ip): bool
    {
        // Reject mapped/transition addresses and non-global networks, including CGNAT and link-local.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) return false;
        $packed = inet_pton($ip);
        if (strlen($packed) === 16) {
            // Only global unicast 2000::/3; exclude transition and special-purpose ranges.
            if ((ord($packed[0]) & 0xe0) !== 0x20 || substr($packed, 0, 2) === hex2bin('2002') || substr($packed, 0, 4) === hex2bin('20010000')) return false;
        } elseif (ord($packed[0]) >= 224) { return false; }
        return true;
    }

    public static function target(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !in_array($parts['scheme'] ?? '', ['http','https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid public HTTP URL');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port !== ($parts['scheme'] === 'https' ? 443 : 80)) { throw new \InvalidArgumentException('Only standard HTTP(S) ports are allowed'); }
        if (filter_var($host, FILTER_VALIDATE_IP)) { $addresses = [$host]; }
        else {
            if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host)) { throw new \InvalidArgumentException('Invalid public DNS name'); }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) { if (isset($record['ip'])) $addresses[] = $record['ip']; if (isset($record['ipv6'])) $addresses[] = $record['ipv6']; }
        }
        if ($addresses === []) { throw new \RuntimeException('Public DNS resolution failed'); }
        foreach ($addresses as $ip) { if (!self::isPublicIp($ip)) { throw new \InvalidArgumentException('Non-public destination denied'); } }
        return [$host, $port, $addresses[0]];
    }

    public function request(string $url, string $method, array $headers = [], string $body = '', int $maxBytes = 1048576): array
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('Secure outbound HTTP requires ext-curl'); }
        [$host, $port, $ip] = self::target($url);
        $ch = curl_init($url);
        $response = '';
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/D', $name) || preg_match('/[\r\n]/', $value)) { throw new \InvalidArgumentException('Invalid outbound header'); }
            $headerLines[] = $name . ': ' . $value;
        }
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headerLines, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response, $maxBytes): int {
                if (strlen($response) + strlen($chunk) > $maxBytes) return 0;
                $response .= $chunk; return strlen($chunk);
            }];
        if (!filter_var($host, FILTER_VALIDATE_IP)) { $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]; }
        if ($method === 'POST') { $options[CURLOPT_POSTFIELDS] = $body; }
        try {
            curl_setopt_array($ch, $options);
            if (curl_exec($ch) === false) { throw new \RuntimeException('Outbound request failed or response exceeds limit'); }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            return [$status, $response];
        } finally { curl_close($ch); }
    }
}
