<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final readonly class RateLimiter
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array{allowed:bool,limit:int,remaining:int,reset_at:int,retry_after:int,key:string} */
    public function hit(string $bucket, string $identity, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $identityHash = hash('sha256', $identity);
        $dir = $this->rootPath . '/storage/rate-limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket) . '-' . $identityHash . '.json';
        $now = time();
        $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset_at' => $now + $windowSeconds, 'retry_after' => $windowSeconds, 'key' => $bucket . ':' . $identityHash];
        }

        try {
            if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('Rate limit lock failed'); }
            // Read the clock after acquiring the inter-process lock.
            $now = time();
            $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];
            $raw = stream_get_contents($handle);
            if ($raw === false) throw new \RuntimeException('Rate limit read failed');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || !is_int($decoded['hits'] ?? null) || $decoded['hits'] < 0 || !is_int($decoded['reset_at'] ?? null) || $decoded['reset_at'] < 0) {
                    throw new \RuntimeException('Corrupt rate limit state');
                }
                $state = $decoded;
            }

            if ($state['reset_at'] <= $now) {
                $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];
            }

            $allowed = $state['hits'] < $limit;
            // Saturate at the limit instead of incrementing on every rejected request.
            if ($allowed) $state['hits']++;
            $remaining = max(0, $limit - $state['hits']);
            $retryAfter = $allowed ? 0 : max(1, $state['reset_at'] - $now);

            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) throw new \RuntimeException('Rate limit persistence failed');
            flock($handle, LOCK_UN);
            fclose($handle);

            return [
                'allowed' => $allowed,
                'limit' => $limit,
                'remaining' => $remaining,
                'reset_at' => $state['reset_at'],
                'retry_after' => $retryAfter,
                'key' => $bucket . ':' . $identityHash,
            ];
        } catch (\Throwable) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            return ['allowed' => false, 'limit' => $limit, 'remaining' => 0, 'reset_at' => $now + $windowSeconds, 'retry_after' => $windowSeconds, 'key' => $bucket . ':' . $identityHash];
        }
    }
}
