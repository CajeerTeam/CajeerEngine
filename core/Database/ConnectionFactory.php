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
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . (string) ($connection['charset'] ?? 'utf8mb4');
            }
        }

        $user = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');
        return new PDO($dsn, $user, $password, $options);
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
        $sslmode = (string) ($connection['sslmode'] ?? '');
        if ($sslmode !== '') {
            $dsn .= ';sslmode=' . $sslmode;
        }
        return $dsn;
    }
}
