<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use CajeerEngine\Runtime\ConfigRepository;
use PDO;
use Throwable;

final class DatabaseManager
{
    private ?PDO $pdo = null;
    private array $schemaCache = [];
    private int $savepointSequence = 0;

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function disconnect(): void { $this->pdo = null; $this->schemaCache = []; }

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

        $factory = new ConnectionFactory($this->config->get('database', []),new \CajeerEngine\Observability\Metrics($this->config->rootPath()));
        $start=hrtime(true);$outcome='failure';
        try{$pdo=$factory->create($driver);$outcome='success';}catch(\PDOException $e){throw new DatabaseUnavailable('Database connection unavailable',0,$e);}
        finally{(new \CajeerEngine\Observability\Metrics($this->config->rootPath()))->buffered('database','connect',$outcome,(hrtime(true)-$start)/1e9);}

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

        $savepoint = null;
        if ($started) $pdo->beginTransaction();
        else {
            $savepoint='ce_sp_'.(++$this->savepointSequence);
            $pdo->exec('SAVEPOINT '.$savepoint);
        }

        $start=hrtime(true);$wall=microtime(true);$trace=\CajeerEngine\Observability\TraceScope::child();$previousTrace=\CajeerEngine\Observability\TraceScope::enter($trace);$outcome='failure';
        try {
            $result = $callback($pdo);
            if ($started) $pdo->commit();
            elseif($savepoint!==null) $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            $outcome='success';return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                if($started)$pdo->rollBack();
                elseif($savepoint!==null){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
            }
            throw $e;
        }finally{\CajeerEngine\Observability\TraceScope::leave($previousTrace);$duration=(hrtime(true)-$start)/1e9;(new \CajeerEngine\Observability\Metrics($this->config->rootPath()))->buffered('database','transaction',$outcome,$duration);(new \CajeerEngine\Observability\TraceExporter($this->config->rootPath()))->record($trace,'database','transaction',$wall,$duration,$outcome==='success');}
    }

    /** Consistent count + page without taking a publication writer lock. */
    public function readSnapshot(callable $callback): mixed
    {
        $pdo=$this->connection();
        if($pdo->inTransaction())return $callback($pdo);
        $driver=$this->driver();
        if($driver==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return $this->transaction(function(PDO $pdo)use($callback,$driver){
            if($driver==='pgsql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            return $callback($pdo);
        });
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
                    'ready' => $this->tableExistsOn($pdo, $driver, 'ce_jobs') && ($driver!=='pgsql'||($this->columnExistsOn($pdo,$driver,'ce_jobs','reservation_token')&&$this->columnExistsOn($pdo,$driver,'ce_jobs','lease_until'))),
                    'lease_columns' => $driver==='pgsql'&&$this->columnExistsOn($pdo,$driver,'ce_jobs','lease_until')&&$this->columnExistsOn($pdo,$driver,'ce_jobs','reservation_token'),
                    'scheduler_ledger' => $driver==='pgsql'&&$this->tableExistsOn($pdo,$driver,'ce_scheduler_runs'),
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
        $key='table:'.$table;
        if(isset($this->schemaCache[$key]))return true;
        $exists=$this->tableExistsOn($this->connection(),$this->driver(),$table);
        if($exists)$this->schemaCache[$key]=true;
        return $exists;
    }

    public function columnExists(string $table, string $column): bool
    {
        $key='column:'.$table.'.'.$column;
        if(isset($this->schemaCache[$key]))return true;
        $exists=$this->columnExistsOn($this->connection(),$this->driver(),$table,$column);
        if($exists)$this->schemaCache[$key]=true;
        return $exists;
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
            'ce_public_route_state',
            'ce_public_routes',
            'ce_cms_search',
        ];

        if($driver==='pgsql')$tables[]='ce_scheduler_runs';
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
            $stmt = $pdo->prepare("SELECT CASE WHEN to_regclass('public.' || :table) IS NOT NULL THEN 1 ELSE 0 END");
            $stmt->execute(['table' => $table]);
            return (int) $stmt->fetchColumn() === 1;
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
