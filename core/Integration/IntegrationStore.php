<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

use CajeerEngine\Update\OperationFiles as F;

/** A durable, locked registry; each mutation is committed atomically. */
final readonly class IntegrationStore
{
    public function __construct(private string $root) {}

    public function transaction(callable $callback, bool $write = false): mixed
    {
        $dir = F::path($this->root, 'storage/app/integrations');
        F::dir($dir, 0700);
        $lock = F::path($this->root, 'storage/app/integrations/registry.lock');
        $handle = fopen($lock, 'c+');
        if (!$handle) throw new \RuntimeException('Cannot open integration registry.');
        try {
            F::permissions($lock, 0600);
            if (!flock($handle, $write ? LOCK_EX : LOCK_SH)) throw new \RuntimeException('Cannot lock integration registry.');
            $path = F::path($this->root, 'storage/app/integrations/registry.json');
            $state = is_file($path) ? F::read($path) : ['version'=>1,'items'=>[]];
            if (($state['version'] ?? null)!==1 || !is_array($state['items'] ?? null)) throw new \RuntimeException('Invalid integration registry.');
            $result = $callback($state['items']);
            if ($write) F::json($path, $state, 0600);
            return $result;
        } finally { fclose($handle); }
    }
}
