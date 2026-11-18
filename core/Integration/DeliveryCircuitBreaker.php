<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

use CajeerEngine\Update\OperationFiles as Files;

/** Durable per-connection circuit breaker shared by independent PHP/queue workers. */
final readonly class DeliveryCircuitBreaker
{
    public function __construct(private string $root, private int $threshold = 5, private int $cooldown = 60)
    {
        if ($threshold < 1 || $threshold > 100 || $cooldown < 1 || $cooldown > 3600) throw new \InvalidArgumentException('Invalid circuit breaker policy');
    }

    public static function configured(string $root): self
    {
        return new self($root, (int)(getenv('INTEGRATIONS_CIRCUIT_THRESHOLD') ?: 5), (int)(getenv('INTEGRATIONS_CIRCUIT_COOLDOWN_SECONDS') ?: 60));
    }

    /** @return ?string Non-null is an exclusive half-open probe ticket. */
    public function claim(string $integrationId): ?string
    {
        return $this->locked($integrationId, function (array &$state): ?string {
            $now = time();
            if ($state['open_until'] > $now || $state['probe_until'] > $now) throw new CircuitOpen('Integration circuit is open');
            if ($state['open_until'] !== 0 || $state['probe_until'] !== 0) {
                // Exactly one worker probes after cooldown. A crashed probe expires.
                $token = bin2hex(random_bytes(16));
                $state['probe_token'] = $token;
                $state['probe_until'] = $now + 30;
                $state['open_until'] = 0;
                return $token;
            }
            return null;
        });
    }

    /** Called after every attempted delivery; 429/5xx/network errors count as transient. */
    public function record(string $integrationId, ?string $ticket, int $status): void
    {
        $this->locked($integrationId, function (array &$state) use ($ticket, $status): void {
            $now = time();
            $transient = $status === 0 || $status === 408 || $status === 425 || $status === 429 || $status >= 500;
            if ($ticket !== null) {
                if (!hash_equals((string)$state['probe_token'], $ticket)) return; // stale probe cannot reopen a healed circuit
                $state['probe_token'] = '';
                $state['probe_until'] = 0;
                $state['failures'] = $transient ? $this->threshold : 0;
                $state['open_until'] = $transient ? $now + $this->cooldown : 0;
                return;
            }
            // Ignore outcomes from concurrent requests while an open/half-open state exists.
            if ($state['open_until'] !== 0 || $state['probe_until'] !== 0) return;
            $state['failures'] = $transient ? min($this->threshold, $state['failures'] + 1) : 0;
            if ($state['failures'] >= $this->threshold) $state['open_until'] = $now + $this->cooldown;
        });
    }

    /** Never store request bodies, credentials, or URLs in circuit files. */
    private function locked(string $id, callable $callback): mixed
    {
        if (!preg_match('/^[a-f0-9-]{32,40}$/D', $id)) throw new \InvalidArgumentException('Invalid integration circuit ID');
        $dir = Files::path($this->root, 'storage/app/integrations/circuits');
        Files::dir($dir, 0700);
        $name = hash('sha256', $id);
        $lockPath = Files::path($this->root, 'storage/app/integrations/circuits/'.$name.'.lock');
        $path = Files::path($this->root, 'storage/app/integrations/circuits/'.$name.'.json');
        $lock = fopen($lockPath, 'c+b');
        if (!$lock) throw new \RuntimeException('Cannot open circuit lock');
        try {
            Files::permissions($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock circuit');
            $state = is_file($path) ? Files::read($path) : ['failures'=>0, 'open_until'=>0, 'probe_until'=>0, 'probe_token'=>''];
            foreach (['failures','open_until','probe_until'] as $key) {
                if (!is_int($state[$key] ?? null) || $state[$key] < 0) throw new \RuntimeException('Invalid circuit state');
            }
            if (!is_string($state['probe_token'] ?? null)) throw new \RuntimeException('Invalid circuit probe token');
            $before = $state;
            $result = $callback($state);
            if ($state !== $before) Files::json($path, $state, 0600);
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}

final class CircuitOpen extends \RuntimeException {}
