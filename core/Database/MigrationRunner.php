<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use PDO;
use Throwable;

final readonly class MigrationRunner
{
    public function __construct(private PDO $pdo, private string $migrationsPath)
    {
    }

    /** @return array{applied:int,batch:int,migrations:list<array<string,mixed>>} */
    public function run(string $driver): array
    {
        $driver = $this->normalizeDriver($driver);
        $this->ensureTable($driver);
        $dir = $this->migrationsPath . '/' . $driver;
        if (!is_dir($dir)) {
            throw new \RuntimeException('Директория миграций не найдена: ' . $dir);
        }

        $batch = $this->nextBatch();
        $applied = $this->applied();
        $count = 0;
        $executed = [];

        foreach ($this->files($dir) as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';

            if (isset($applied[$name])) {
                $status = (string) ($applied[$name]['status'] ?? 'applied');
                $knownChecksum = (string) ($applied[$name]['checksum'] ?? '');
                if ($status === 'applied') {
                    if ($knownChecksum !== '' && $knownChecksum !== $checksum) {
                        throw new \RuntimeException("Checksum миграции изменился после применения: {$name}");
                    }
                    continue;
                }

                if ($status !== 'failed') {
                    throw new \RuntimeException("Неизвестный статус миграции {$name}: {$status}");
                }

                $this->deleteFailedMigration($name);
            }

            $startedAt = microtime(true);
            $sql = trim((string) file_get_contents($file));
            $this->pdo->beginTransaction();
            try {
                if ($sql !== '') {
                    $this->pdo->exec($sql);
                }
                $this->insertMigration($name, $checksum, $batch, (int) round((microtime(true) - $startedAt) * 1000), 'applied', null);
                $this->pdo->commit();
                $count++;
                $executed[] = ['migration' => $name, 'checksum' => $checksum, 'batch' => $batch, 'status' => 'applied'];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->insertMigration($name, $checksum, $batch, (int) round((microtime(true) - $startedAt) * 1000), 'failed', $e->getMessage());
                throw $e;
            }
        }

        return ['driver' => $driver, 'applied' => $count, 'batch' => $batch, 'migrations' => $executed];
    }

    /** @return array{driver:string,applied:list<array<string,mixed>>,pending:list<array<string,mixed>>,failed:list<array<string,mixed>>} */
    public function status(string $driver): array
    {
        $driver = $this->normalizeDriver($driver);
        $this->ensureTable($driver);
        $dir = $this->migrationsPath . '/' . $driver;
        if (!is_dir($dir)) {
            throw new \RuntimeException('Директория миграций не найдена: ' . $dir);
        }

        $applied = $this->applied();
        $appliedRows = [];
        $pendingRows = [];
        $failedRows = [];

        foreach ($this->files($dir) as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';
            if (!isset($applied[$name])) {
                $pendingRows[] = ['migration' => $name, 'checksum' => $checksum];
                continue;
            }

            $row = $applied[$name];
            $row['current_checksum'] = $checksum;
            $row['checksum_ok'] = ($row['checksum'] ?? '') === '' || ($row['checksum'] ?? '') === $checksum;
            if (($row['status'] ?? 'applied') === 'failed') {
                $failedRows[] = $row;
            } else {
                $appliedRows[] = $row;
            }
        }

        return [
            'driver' => $driver,
            'applied' => array_values($appliedRows),
            'pending' => array_values($pendingRows),
            'failed' => array_values($failedRows),
        ];
    }


    /** @return array{driver:string,removed:int,removed_migrations:list<string>,rerun?:array<string,mixed>} */
    public function repairFailed(string $driver, bool $runAfterRepair = false): array
    {
        $driver = $this->normalizeDriver($driver);
        $this->ensureTable($driver);
        $status = $this->status($driver);
        $removed = [];
        foreach ($status['failed'] as $row) {
            $migration = (string) ($row['migration'] ?? '');
            if ($migration === '') {
                continue;
            }
            $this->deleteFailedMigration($migration);
            $removed[] = $migration;
        }

        $result = [
            'driver' => $driver,
            'removed' => count($removed),
            'removed_migrations' => $removed,
        ];
        if ($runAfterRepair) {
            $result['rerun'] = $this->run($driver);
        }
        return $result;
    }

    private function ensureTable(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ce_migrations (
                migration VARCHAR(190) PRIMARY KEY,
                batch INT NOT NULL DEFAULT 1,
                checksum VARCHAR(64) NOT NULL DEFAULT '',
                execution_ms INT NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'applied',
                error TEXT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            return;
        }

        if ($driver === 'pgsql') {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ce_migrations (
                migration VARCHAR(190) PRIMARY KEY,
                batch INT NOT NULL DEFAULT 1,
                checksum VARCHAR(64) NOT NULL DEFAULT '',
                execution_ms INT NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT 'applied',
                error TEXT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            foreach ([
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS batch INT NOT NULL DEFAULT 1",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) NOT NULL DEFAULT ''",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS execution_ms INT NOT NULL DEFAULT 0",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'applied'",
                "ALTER TABLE ce_migrations ADD COLUMN IF NOT EXISTS error TEXT NULL",
            ] as $sql) {
                $this->pdo->exec($sql);
            }
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS ce_migrations (
            migration VARCHAR(190) PRIMARY KEY,
            batch INT NOT NULL DEFAULT 1,
            checksum VARCHAR(64) NOT NULL DEFAULT '',
            execution_ms INT NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'applied',
            error TEXT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addColumnIfMissing('ce_migrations', 'batch', 'INT NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('ce_migrations', 'checksum', "VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addColumnIfMissing('ce_migrations', 'execution_ms', 'INT NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('ce_migrations', 'status', "VARCHAR(40) NOT NULL DEFAULT 'applied'");
        $this->addColumnIfMissing('ce_migrations', 'error', 'TEXT NULL');
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function applied(): array
    {
        $rows = $this->pdo->query('SELECT migration, batch, checksum, execution_ms, status, error, applied_at FROM ce_migrations ORDER BY applied_at ASC, migration ASC')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['migration']] = $row;
        }
        return $result;
    }

    private function nextBatch(): int
    {
        $batch = $this->pdo->query('SELECT MAX(batch) FROM ce_migrations')->fetchColumn();
        return ((int) $batch) + 1;
    }

    private function insertMigration(string $migration, string $checksum, int $batch, int $executionMs, string $status, ?string $error): void
    {
        $this->deleteFailedMigration($migration);
        $stmt = $this->pdo->prepare('INSERT INTO ce_migrations (migration, batch, checksum, execution_ms, status, error, applied_at) VALUES (:migration, :batch, :checksum, :execution_ms, :status, :error, CURRENT_TIMESTAMP)');
        $stmt->execute([
            'migration' => $migration,
            'batch' => $batch,
            'checksum' => $checksum,
            'execution_ms' => $executionMs,
            'status' => $status,
            'error' => $error,
        ]);
    }

    private function deleteFailedMigration(string $migration): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ce_migrations WHERE migration = :migration AND status = :status');
        $stmt->execute(['migration' => $migration, 'status' => 'failed']);
    }

    /** @return list<string> */
    private function files(string $dir): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return array_values($files);
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower($driver);
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
