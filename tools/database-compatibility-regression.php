#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Database/MigrationSqlExecutor.php';
require dirname(__DIR__) . '/core/Database/MigrationRunner.php';

use CajeerEngine\Database\MigrationSqlExecutor;
use CajeerEngine\Database\MigrationRunner;

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** A protocol-level PDO stub: no server or PDO driver extension is required. */
final class CompatStatement extends PDOStatement
{
    public function __construct(private readonly bool $exists) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->exists ? 1 : 0; }
}
final class CompatPDO extends PDO
{
    /** @var list<string> */
    public array $queries = [];
    public function __construct(private readonly string $dialect = 'mysql', private readonly bool $exists = false) {}
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? $this->dialect : null;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ensure(str_contains($query, 'information_schema.columns'), 'Conditional column must inspect information_schema');
        return new CompatStatement($this->exists);
    }
    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;
        return 0;
    }
}

$root = dirname(__DIR__);
$count = 0;
foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
    foreach (glob($root . '/migrations/' . $driver . '/*.sql') ?: [] as $file) {
        $sql = file_get_contents($file);
        ensure(is_string($sql) && trim($sql) !== '', 'Unreadable/empty migration: ' . $file);
        $statements = MigrationSqlExecutor::statements($sql);
        ensure(count($statements) > 0, 'Migration was not parsed: ' . $file);
        foreach ($statements as $statement) {
            ensure(!str_starts_with($statement, '--'), 'SQL comments leaked into executable statement');
        }
        $count++;
    }
}
$quotes = "-- comment ; ignored\nINSERT INTO t (v) VALUES ('a; b', 'c'';d'); /* block ; comment */\nSELECT `semi;colon` FROM t; # comment ;\n";
$parsed = MigrationSqlExecutor::statements($quotes);
ensure(count($parsed) === 2 && str_contains($parsed[0], "'a; b'") && str_contains($parsed[1], '`semi;colon`'), 'Quoted semicolons or comments were split incorrectly');
try {
    MigrationSqlExecutor::statements("INSERT INTO t VALUES ('unterminated);");
    throw new RuntimeException('Unterminated SQL literal was accepted');
} catch (RuntimeException $e) {
    ensure(str_contains($e->getMessage(), 'Unterminated'), $e->getMessage());
}
foreach (['0005_queue_search_webhooks.sql', '0007_queue_runtime_columns.sql'] as $migration) {
    $sql = file_get_contents($root . '/migrations/mysql/' . $migration);
    ensure(is_string($sql), 'Missing legacy migration');
    $missing = new CompatPDO();
    MigrationSqlExecutor::execute($missing, 'mysql', $sql);
    ensure(count(array_filter($missing->queries, static fn (string $q): bool => str_contains($q, 'ADD COLUMN'))) === 6, 'MySQL 8.4 did not receive normalized ALTER TABLE statements');
    foreach ($missing->queries as $query) {
        ensure(!str_contains($query, 'ADD COLUMN IF NOT EXISTS'), 'Incompatible ADD COLUMN syntax reached MySQL');
    }
    $present = new CompatPDO('mysql', true);
    MigrationSqlExecutor::execute($present, 'mysql', $sql);
    ensure(count(array_filter($present->queries, static fn (string $q): bool => str_contains($q, 'ADD COLUMN'))) === 0, 'Repeated migration did not skip existing columns');
    $unmodified = new CompatPDO();
    MigrationSqlExecutor::execute($unmodified, 'pgsql', $sql);
    ensure(str_contains($unmodified->queries[0], 'ADD COLUMN IF NOT EXISTS'), 'Postgres dialect was unexpectedly modified');
}
$wrong = new CompatPDO('sqlite');
$runner = new MigrationRunner($wrong, $root . '/migrations');
foreach (['mysql', 'pgsql'] as $driver) {
    try {
        $runner->run($driver);
        throw new RuntimeException('Migration with incompatible PDO driver was accepted');
    } catch (InvalidArgumentException $e) {
        ensure(str_contains($e->getMessage(), 'does not match'), 'Unexpected dialect rejection');
    }
}
echo "OK: $count migration scripts parsed; MySQL 8.4 conditional columns, quoted statements, and dialect mismatch regression\n";
