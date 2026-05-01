<?php

declare(strict_types=1);

namespace Cajeer\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo, private readonly string $migrationsPath) {}

    public function run(string $driver, bool $dryRun = false): int
    {
        $this->ensureRepository();
        $executed = $this->executed();
        $batch = $this->nextBatch();
        $count = 0;

        foreach ($this->files($driver) as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';
            if (isset($executed[$name])) {
                if (($executed[$name]['checksum'] ?? '') !== '' && $executed[$name]['checksum'] !== $checksum) {
                    throw new RuntimeException("Миграция уже выполнена, но checksum изменился: {$name}");
                }
                continue;
            }
            if ($dryRun) {
                $count++;
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare('INSERT INTO cajeer_migrations (migration, batch, checksum, driver, executed_at) VALUES (:migration, :batch, :checksum, :driver, :executed_at)');
                $stmt->execute([
                    'migration' => $name,
                    'batch' => $batch,
                    'checksum' => $checksum,
                    'driver' => $driver,
                    'executed_at' => date(DATE_ATOM),
                ]);
                $this->pdo->commit();
                $count++;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return $count;
    }

    public function status(string $driver): array
    {
        $this->ensureRepository();
        $executed = $this->executed();
        $rows = [];
        foreach ($this->files($driver) as $file) {
            $name = basename($file);
            $rows[] = [
                'migration' => $name,
                'ran' => isset($executed[$name]),
                'checksum' => hash_file('sha256', $file) ?: '',
                'executed_at' => $executed[$name]['executed_at'] ?? null,
            ];
        }
        return $rows;
    }

    private function ensureRepository(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS cajeer_migrations (migration VARCHAR(255) PRIMARY KEY, batch INTEGER NOT NULL DEFAULT 1, checksum VARCHAR(64) NOT NULL DEFAULT \'\', driver VARCHAR(32) NOT NULL DEFAULT \'unknown\', executed_at VARCHAR(64) NOT NULL)');
        $columns = $this->pdo->query('SELECT * FROM cajeer_migrations LIMIT 0');
        $known = array_map(static fn ($meta) => $meta['name'], $columns?->getColumnMeta(0) ? [] : []);
        unset($known);
        try { $this->pdo->exec('ALTER TABLE cajeer_migrations ADD COLUMN batch INTEGER NOT NULL DEFAULT 1'); } catch (\Throwable) {}
        try { $this->pdo->exec('ALTER TABLE cajeer_migrations ADD COLUMN checksum VARCHAR(64) NOT NULL DEFAULT \'\''); } catch (\Throwable) {}
        try { $this->pdo->exec('ALTER TABLE cajeer_migrations ADD COLUMN driver VARCHAR(32) NOT NULL DEFAULT \'unknown\''); } catch (\Throwable) {}
    }

    private function executed(): array
    {
        $rows = $this->pdo->query('SELECT migration, batch, checksum, driver, executed_at FROM cajeer_migrations')->fetchAll() ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['migration']] = $row;
        }
        return $map;
    }

    private function nextBatch(): int
    {
        $value = $this->pdo->query('SELECT MAX(batch) FROM cajeer_migrations')->fetchColumn();
        return ((int) $value) + 1;
    }

    private function files(string $driver): array
    {
        $path = $this->migrationsPath . '/' . $driver;
        if (!is_dir($path)) {
            throw new RuntimeException("Каталог миграций не найден: {$path}");
        }
        $files = glob($path . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        return $files;
    }
}
