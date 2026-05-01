<?php

declare(strict_types=1);

namespace Cajeer\Database;

use PDO;
use RuntimeException;

final class QueryBuilder
{
    /** @var array<int,array{column:string,operator:string,value:mixed}> */
    private array $where = [];
    /** @var array<string,mixed> */
    private array $bindings = [];
    private ?string $table = null;
    private ?string $orderBy = null;
    private string $orderDirection = 'ASC';
    private ?int $limit = null;

    public function __construct(private readonly PDO $pdo) {}

    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->table = $this->identifier($table);
        $clone->where = [];
        $clone->bindings = [];
        $clone->orderBy = null;
        $clone->limit = null;
        return $clone;
    }

    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        $clone = clone $this;
        $operator = $value === null ? '=' : strtoupper((string) $operatorOrValue);
        $actualValue = $value === null ? $operatorOrValue : $value;
        if (!in_array($operator, ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE'], true)) {
            throw new RuntimeException('Неподдерживаемый оператор WHERE.');
        }
        $clone->where[] = ['column' => $this->identifier($column), 'operator' => $operator, 'value' => $actualValue];
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $clone = clone $this;
        $clone->orderBy = $this->identifier($column);
        $clone->orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(1, min($limit, 500));
        return $clone;
    }

    public function first(): ?array
    {
        $rows = $this->limit(1)->get();
        return $rows[0] ?? null;
    }

    public function get(): array
    {
        $sql = 'SELECT * FROM ' . $this->requiredTable();
        $params = [];
        if ($this->where) {
            $parts = [];
            foreach ($this->where as $i => $condition) {
                $param = ':w' . $i;
                $parts[] = $condition['column'] . ' ' . $condition['operator'] . ' ' . $param;
                $params[$param] = $condition['value'];
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . $this->orderBy . ' ' . $this->orderDirection;
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function insert(array $data): int|string
    {
        $columns = array_map([$this, 'identifier'], array_keys($data));
        $placeholders = array_map(static fn (string $column): string => ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column), array_keys($data));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->requiredTable(), implode(', ', $columns), implode(', ', $placeholders));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_combine($placeholders, array_values($data)) ?: []);
        return $this->pdo->lastInsertId();
    }

    public function update(array $data): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $param = ':u_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $column);
            $sets[] = $this->identifier((string) $column) . ' = ' . $param;
            $params[$param] = $value;
        }
        $sql = 'UPDATE ' . $this->requiredTable() . ' SET ' . implode(', ', $sets);
        if ($this->where) {
            $parts = [];
            foreach ($this->where as $i => $condition) {
                $param = ':w' . $i;
                $parts[] = $condition['column'] . ' ' . $condition['operator'] . ' ' . $param;
                $params[$param] = $condition['value'];
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function delete(): int
    {
        $params = [];
        $sql = 'DELETE FROM ' . $this->requiredTable();
        if ($this->where) {
            $parts = [];
            foreach ($this->where as $i => $condition) {
                $param = ':w' . $i;
                $parts[] = $condition['column'] . ' ' . $condition['operator'] . ' ' . $param;
                $params[$param] = $condition['value'];
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function requiredTable(): string
    {
        if (!$this->table) {
            throw new RuntimeException('Таблица не выбрана.');
        }
        return $this->table;
    }

    private function identifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Некорректный SQL identifier: ' . $identifier);
        }
        return $identifier;
    }
}
