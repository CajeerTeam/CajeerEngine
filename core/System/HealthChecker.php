<?php

declare(strict_types=1);

namespace Cajeer\System;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Throwable;

final class HealthChecker
{
    public function __construct(private readonly Container $container, private readonly string $basePath) {}

    public function summary(): array
    {
        $checks = $this->checks();
        return ['status' => in_array(false, array_column($checks, 'ok'), true) ? 'degraded' : 'ok', 'checks' => $checks];
    }

    public function checks(): array
    {
        return [
            ['name' => 'php_version', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>='), 'value' => PHP_VERSION],
            ['name' => 'storage_writable', 'ok' => is_writable($this->basePath . '/storage'), 'value' => $this->basePath . '/storage'],
            ['name' => 'uploads_writable', 'ok' => is_writable($this->basePath . '/public/uploads'), 'value' => $this->basePath . '/public/uploads'],
            ['name' => 'installed', 'ok' => is_file($this->basePath . '/storage/installed.lock'), 'value' => is_file($this->basePath . '/storage/installed.lock') ? 'yes' : 'no'],
            ['name' => 'db_connection', 'ok' => $this->dbOk(), 'value' => 'default'],
            ['name' => 'disk_free_mb', 'ok' => disk_free_space($this->basePath) > 64 * 1024 * 1024, 'value' => (string) round((disk_free_space($this->basePath) ?: 0) / 1024 / 1024)],
        ];
    }

    private function dbOk(): bool
    {
        try {
            if (!$this->container->has(DatabaseManager::class)) return false;
            $this->container->get(DatabaseManager::class)->connection()->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
