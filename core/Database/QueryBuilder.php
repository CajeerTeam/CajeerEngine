<?php

declare(strict_types=1);

namespace Cajeer\Database;

use PDO;

final class QueryBuilder
{
    public function __construct(private readonly PDO $pdo) {}

    public function first(string $table, string $column, mixed $value): ?array
    {
        $sql = "SELECT * FROM {$table} WHERE {$column} = :value LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['value' => $value]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function all(string $table, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->query("SELECT * FROM {$table} LIMIT {$limit}");
        return $stmt->fetchAll();
    }

    public function insert(string $table, array $data): int|string
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $placeholders));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return $this->pdo->lastInsertId();
    }
}
