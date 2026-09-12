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

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit - 1, 'reset_at' => $now + $windowSeconds, 'retry_after' => 0, 'key' => $bucket . ':' . $identityHash];
        }

        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $state['hits'] = (int) ($decoded['hits'] ?? 0);
                    $state['reset_at'] = (int) ($decoded['reset_at'] ?? ($now + $windowSeconds));
                }
            }

            if ($state['reset_at'] <= $now) {
                $state = ['hits' => 0, 'reset_at' => $now + $windowSeconds];
            }

            $state['hits']++;
            $allowed = $state['hits'] <= $limit;
            $remaining = max(0, $limit - $state['hits']);
            $retryAfter = $allowed ? 0 : max(1, $state['reset_at'] - $now);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
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
            return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit - 1, 'reset_at' => $now + $windowSeconds, 'retry_after' => 0, 'key' => $bucket . ':' . $identityHash];
        }
    }
}
