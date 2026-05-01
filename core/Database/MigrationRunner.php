<?php

declare(strict_types=1);

namespace Cajeer\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo, private readonly string $migrationsPath) {}

    public function run(string $driver): int
    {
        $path = $this->migrationsPath . '/' . $driver;
        if (!is_dir($path)) {
            throw new RuntimeException("Каталог миграций не найден: {$path}");
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS cajeer_migrations (migration VARCHAR(255) PRIMARY KEY, executed_at VARCHAR(64) NOT NULL)');
        $executed = $this->pdo->query('SELECT migration FROM cajeer_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $count = 0;

        foreach (glob($path . '/*.sql') ?: [] as $file) {
            $name = basename($file);
            if (in_array($name, $executed, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare('INSERT INTO cajeer_migrations (migration, executed_at) VALUES (:migration, :executed_at)');
                $stmt->execute(['migration' => $name, 'executed_at' => date(DATE_ATOM)]);
                $this->pdo->commit();
                $count++;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return $count;
    }
}
