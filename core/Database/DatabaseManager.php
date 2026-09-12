<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use CajeerEngine\Runtime\ConfigRepository;
use PDO;
use Throwable;

final class DatabaseManager
{
    private ?PDO $pdo = null;

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function driver(): string
    {
        $driver = strtolower($this->config->string('database.default', 'pgsql'));
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    public function migrationDriver(): string
    {
        return $this->driver();
    }

    public function connection(?string $driver = null): PDO
    {
        if ($driver === null && $this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $factory = new ConnectionFactory($this->config->get('database', []));
        $pdo = $factory->create($driver);

        if ($driver === null) {
            $this->pdo = $pdo;
        }

        return $pdo;
    }

    /** @template T @param callable(PDO):T $callback @return T */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();
        $started = !$pdo->inTransaction();

        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $startedAt = microtime(true);
        $driver = $this->driver();

        try {
            $pdo = $this->connection();
            $pdo->query('SELECT 1')->fetchColumn();
            $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $tables = $this->coreTablesStatus($pdo, $driver);
            $contentColumns = $this->contentCoreColumnsStatus($pdo, $driver);
            $securityColumns = $this->securityCoreColumnsStatus($pdo, $driver);

            return [
                'ok' => true,
                'driver' => $driver,
                'server_version' => $serverVersion,
                'database' => $this->databaseName(),
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'tables' => $tables,
                'missing_tables' => array_values(array_keys(array_filter($tables, static fn (bool $exists): bool => !$exists))),
                'schema_ready' => !in_array(false, $tables, true) && !in_array(false, $contentColumns, true) && !in_array(false, $securityColumns, true),
                'content_core' => [
                    'ready' => !in_array(false, $contentColumns, true),
                    'columns' => $contentColumns,
                    'missing_columns' => array_values(array_keys(array_filter($contentColumns, static fn (bool $exists): bool => !$exists))),
                ],
                'security_core' => [
                    'ready' => !in_array(false, $securityColumns, true),
                    'columns' => $securityColumns,
                    'missing_columns' => array_values(array_keys(array_filter($securityColumns, static fn (bool $exists): bool => !$exists))),
                ],
                'queue_core' => [
                    'ready' => $this->tableExistsOn($pdo, $driver, 'ce_jobs'),
                    'failed_jobs_table' => $this->tableExistsOn($pdo, $driver, 'ce_failed_jobs'),
                    'webhook_deliveries_table' => $this->tableExistsOn($pdo, $driver, 'ce_webhook_deliveries'),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'driver' => $driver,
                'database' => $this->databaseName(),
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'error' => [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    public function tableExists(string $table): bool
    {
        return $this->tableExistsOn($this->connection(), $this->driver(), $table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->columnExistsOn($this->connection(), $this->driver(), $table, $column);
    }

    public function contentCoreReady(): bool
    {
        try {
            if (!$this->tableExists('ce_content_types') || !$this->tableExists('ce_content_entries') || !$this->tableExists('ce_content_revisions')) {
                return false;
            }

            foreach (['title', 'revision_number', 'translation_group', 'deleted_at'] as $column) {
                if (!$this->columnExists('ce_content_entries', $column)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }


    public function securityCoreReady(): bool
    {
        try {
            if (!$this->tableExists('ce_users') || !$this->tableExists('ce_roles') || !$this->tableExists('ce_api_tokens')) {
                return false;
            }

            foreach (['status', 'last_login_at', 'disabled_at', 'two_factor_recovery_codes'] as $column) {
                if (!$this->columnExists('ce_users', $column)) {
                    return false;
                }
            }

            foreach (['token_prefix', 'revoked_at'] as $column) {
                if (!$this->columnExists('ce_api_tokens', $column)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, bool> */
    private function securityCoreColumnsStatus(PDO $pdo, string $driver): array
    {
        $columns = [
            'ce_users.status' => ['ce_users', 'status'],
            'ce_users.last_login_at' => ['ce_users', 'last_login_at'],
            'ce_users.disabled_at' => ['ce_users', 'disabled_at'],
            'ce_users.password_changed_at' => ['ce_users', 'password_changed_at'],
            'ce_users.email_verified_at' => ['ce_users', 'email_verified_at'],
            'ce_users.two_factor_recovery_codes' => ['ce_users', 'two_factor_recovery_codes'],
            'ce_api_tokens.token_prefix' => ['ce_api_tokens', 'token_prefix'],
            'ce_api_tokens.revoked_at' => ['ce_api_tokens', 'revoked_at'],
        ];

        $result = [];
        foreach ($columns as $key => [$table, $column]) {
            $result[$key] = $this->tableExistsOn($pdo, $driver, $table) && $this->columnExistsOn($pdo, $driver, $table, $column);
        }
        return $result;
    }

    /** @return array<string, bool> */
    private function coreTablesStatus(PDO $pdo, string $driver): array
    {
        $tables = [
            'ce_migrations',
            'ce_users',
            'ce_roles',
            'ce_api_tokens',
            'ce_content_types',
            'ce_content_entries',
            'ce_content_revisions',
            'ce_settings',
            'ce_settings_history',
            'ce_audit_log',
            'ce_jobs',
            'ce_media',
            'ce_webhooks',
            'ce_search_documents',
            'ce_outbox',
            'ce_webhook_deliveries',
            'ce_failed_jobs',
        ];

        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $this->tableExistsOn($pdo, $driver, $table);
        }
        return $result;
    }

    /** @return array<string, bool> */
    private function contentCoreColumnsStatus(PDO $pdo, string $driver): array
    {
        $columns = [
            'ce_content_entries.title' => ['ce_content_entries', 'title'],
            'ce_content_entries.revision_number' => ['ce_content_entries', 'revision_number'],
            'ce_content_entries.translation_group' => ['ce_content_entries', 'translation_group'],
            'ce_content_entries.deleted_at' => ['ce_content_entries', 'deleted_at'],
            'ce_content_revisions.data' => ['ce_content_revisions', 'data'],
        ];

        $result = [];
        foreach ($columns as $key => [$table, $column]) {
            $result[$key] = $this->tableExistsOn($pdo, $driver, $table) && $this->columnExistsOn($pdo, $driver, $table, $column);
        }
        return $result;
    }

    private function tableExistsOn(PDO $pdo, string $driver, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Некорректное имя таблицы.');
        }

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare("SELECT to_regclass('public.' || :table) IS NOT NULL");
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        }

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute(['table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExistsOn(PDO $pdo, string $driver, string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException('Некорректное имя таблицы или колонки.');
        }

        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column');
            $stmt->execute(['table' => $table, 'column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        }

        if ($driver === 'sqlite') {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($stmt->fetchAll() as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function databaseName(): string
    {
        return $this->config->string('database.connections.' . $this->driver() . '.database', '');
    }
}
