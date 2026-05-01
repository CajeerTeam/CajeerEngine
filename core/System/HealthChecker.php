<?php

declare(strict_types=1);

namespace Cajeer\System;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Database\MigrationRunner;
use Cajeer\Extensions\ExtensionRegistry;
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
            ['name' => 'pdo', 'ok' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? 'yes' : 'no'],
            ['name' => 'storage_writable', 'ok' => is_writable($this->basePath . '/storage'), 'value' => $this->basePath . '/storage'],
            ['name' => 'cache_writable', 'ok' => is_writable($this->basePath . '/storage/cache'), 'value' => $this->basePath . '/storage/cache'],
            ['name' => 'uploads_writable', 'ok' => is_writable($this->basePath . '/public/uploads'), 'value' => $this->basePath . '/public/uploads'],
            ['name' => 'installed_lock', 'ok' => is_file($this->basePath . '/storage/installed.lock'), 'value' => is_file($this->basePath . '/storage/installed.lock') ? 'yes' : 'no'],
            ['name' => 'db_connection', 'ok' => $this->dbOk(), 'value' => 'default'],
            ['name' => 'migration_status', 'ok' => $this->migrationsOk(), 'value' => $this->migrationValue()],
            ['name' => 'enabled_extensions', 'ok' => true, 'value' => (string) $this->extensionCount()],
            ['name' => 'disk_free_mb', 'ok' => (disk_free_space($this->basePath) ?: 0) > 64 * 1024 * 1024, 'value' => (string) round((disk_free_space($this->basePath) ?: 0) / 1024 / 1024)],
        ];
    }

    private function dbOk(): bool
    {
        try { $this->container->get(DatabaseManager::class)->connection()->query('SELECT 1'); return true; }
        catch (Throwable) { return false; }
    }

    private function migrationsOk(): bool
    {
        try {
            $db = $this->container->get(DatabaseManager::class);
            $driver = (string) $db->connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $status = (new MigrationRunner($db->connection(), $this->basePath . '/migrations'))->status($driver);
            return !in_array(false, array_column($status, 'ran'), true);
        } catch (Throwable) { return false; }
    }

    private function migrationValue(): string
    {
        try {
            $db = $this->container->get(DatabaseManager::class);
            $driver = (string) $db->connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $status = (new MigrationRunner($db->connection(), $this->basePath . '/migrations'))->status($driver);
            $ran = count(array_filter($status, static fn ($row) => $row['ran']));
            return $ran . '/' . count($status);
        } catch (Throwable $e) { return 'unknown'; }
    }

    private function extensionCount(): int
    {
        try { return count($this->container->get(ExtensionRegistry::class)->all()); }
        catch (Throwable) { return 0; }
    }
}
