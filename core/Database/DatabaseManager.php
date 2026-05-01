<?php

declare(strict_types=1);

namespace Cajeer\Database;

use Cajeer\Config\ConfigRepository;
use PDO;
use RuntimeException;

final class DatabaseManager
{
    /** @var array<string,PDO> */
    private array $connections = [];

    public function __construct(private readonly ConfigRepository $config) {}

    public function connection(?string $name = null): PDO
    {
        $name ??= (string) $this->config->get('database.default', 'mysql');
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $connection = $this->config->get('database.connections.' . $name);
        if (!is_array($connection)) {
            throw new RuntimeException("База данных не настроена: {$name}");
        }

        $driver = $connection['driver'] ?? $name;
        $dsn = match ($driver) {
            'mysql' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $connection['host'], $connection['port'], $connection['database'], $connection['charset'] ?? 'utf8mb4'),
            'pgsql' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            default => throw new RuntimeException("Неподдерживаемый драйвер: {$driver}"),
        };

        $pdo = new PDO($dsn, (string) $connection['username'], (string) $connection['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->connections[$name] = $pdo;
        return $pdo;
    }
}
