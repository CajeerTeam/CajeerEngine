<?php

declare(strict_types=1);

namespace CajeerEngine\Database;

use PDO;

final readonly class ConnectionFactory
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public static function fromConfigFile(string $rootPath): self
    {
        $path = $rootPath . '/config/database.php';
        if (!is_file($path)) {
            throw new \RuntimeException('config/database.php не найден.');
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new \RuntimeException('config/database.php должен возвращать массив.');
        }
        return new self($config);
    }

    public function create(?string $driver = null): PDO
    {
        $driver = strtolower($driver ?: (string) ($this->config['default'] ?? 'pgsql'));
        $driver = $driver === 'mariadb' ? 'mysql' : $driver;

        $connections = is_array($this->config['connections'] ?? null) ? $this->config['connections'] : [];
        $connection = is_array($connections[$driver] ?? null) ? $connections[$driver] : [];
        if ($connection === []) {
            throw new \RuntimeException('DB connection не настроен: ' . $driver);
        }

        $dsn = (string) ($connection['dsn'] ?? '');
        if ($dsn === '') {
            $dsn = $this->dsn($driver, $connection);
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($driver === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            $options[PDO::ATTR_TIMEOUT] = self::bounded($connection,'connect_timeout',5,1,60);
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . (string) ($connection['charset'] ?? 'utf8mb4');
            }
        }

        $user = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        $pdo=new PDO($dsn, $user, $password, $options);
        if($driver==='sqlite'){
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = '.self::bounded($connection,'busy_timeout_ms',5000,0,60000));
            // WAL is opt-in: do not change journal mode on every connection by default.
            if(($connection['wal']??false)===true){
                $mode=$pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
                if($mode!=='wal'&&$mode!=='memory')throw new \RuntimeException('Cannot enable SQLite WAL');
            }
        }elseif($driver==='pgsql'){
            $pdo->exec('SET statement_timeout = '.self::bounded($connection,'statement_timeout_ms',30000,0,3600000));
            $pdo->exec('SET lock_timeout = '.self::bounded($connection,'lock_timeout_ms',5000,0,600000));
            $pdo->exec('SET idle_in_transaction_session_timeout = '.self::bounded($connection,'idle_transaction_timeout_ms',60000,0,3600000));
        }elseif($driver==='mysql'){
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = '.self::bounded($connection,'lock_timeout_seconds',5,1,600));
        }
        return $pdo;
    }

    private static function bounded(array $connection,string $key,int $default,int $min,int $max): int
    {
        $value=filter_var($connection[$key]??$default,FILTER_VALIDATE_INT);
        if($value===false||$value<$min||$value>$max)throw new \InvalidArgumentException('Invalid database option: '.$key);
        return $value;
    }

    /** @param array<string, mixed> $connection */
    private function dsn(string $driver, array $connection): string
    {
        return match ($driver) {
            'sqlite' => $this->sqliteDsn($connection),
            'pgsql' => $this->pgsqlDsn($connection),
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $connection['host'] ?? '127.0.0.1',
                $connection['port'] ?? 3306,
                $connection['database'] ?? 'cajeerengine',
                $connection['charset'] ?? 'utf8mb4'
            ),
            default => throw new \RuntimeException('Неподдерживаемый DB driver: ' . $driver),
        };
    }

    /** @param array<string, mixed> $connection */
    private function sqliteDsn(array $connection): string
    {
        $database = (string) ($connection['database'] ?? 'storage/database/cajeer.sqlite');
        if ($database === ':memory:') {
            return 'sqlite::memory:';
        }
        if (!str_starts_with($database, '/')) {
            $database = dirname(__DIR__, 2) . '/' . ltrim($database, '/');
        }
        $dir = dirname($database);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать директорию SQLite: ' . $dir);
        }
        return 'sqlite:' . $database;
    }

    /** @param array<string, mixed> $connection */
    private function pgsqlDsn(array $connection): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $connection['host'] ?? '127.0.0.1',
            $connection['port'] ?? 5432,
            $connection['database'] ?? 'cajeerengine'
        );
        $dsn .= ';connect_timeout='.self::bounded($connection,'connect_timeout',5,1,60);
        $sslmode = (string) ($connection['sslmode'] ?? '');
        if ($sslmode !== '') {
            $dsn .= ';sslmode=' . $sslmode;
        }
        return $dsn;
    }
}
