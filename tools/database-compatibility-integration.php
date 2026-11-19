#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Full isolated-schema migration test; only runs on the dedicated release CI services. */
if (PHP_SAPI !== 'cli' || getenv('DB_COMPAT_FIXTURE') !== '1') {
    fwrite(STDERR, "Set DB_COMPAT_FIXTURE=1 on disposable CI services only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/core/Database/MigrationSqlExecutor.php';
require $root . '/core/Database/MigrationLock.php';
require $root . '/core/Database/MigrationRunner.php';

use CajeerEngine\Database\MigrationRunner;

function dbCheck(bool $pass, string $message): void
{
    if (!$pass) {
        throw new RuntimeException($message);
    }
}

function exercise(PDO $pdo, string $driver, string $root, string $label): void
{
    $migrations = new MigrationRunner($pdo, $root . '/migrations');
    $expected = count(glob($root . '/migrations/' . $driver . '/*.sql') ?: []);
    $first = $migrations->run($driver);
    dbCheck($first['applied'] === $expected, "$driver: initial migration count mismatch ($expected expected)");
    $status = $migrations->status($driver);
    dbCheck(count($status['applied']) === $expected, "$driver: applied migration ledger incomplete");
    dbCheck($status['failed'] === [] && $status['pending'] === [] && $status['integrity_errors'] === [], "$driver: migration status not clean");
    dbCheck($migrations->run($driver)['applied'] === 0, "$driver: migration re-run was not idempotent");
    foreach (['ce_users', 'ce_api_tokens', 'ce_jobs', 'ce_ai_automations', 'ce_ai_automation_runs'] as $table) {
        $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    $originalCount = (int) $pdo->query('SELECT COUNT(*) FROM ce_migrations')->fetchColumn();
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO ce_migrations (migration,batch,checksum,status) VALUES ('9998_rollback_probe.sql',999,'probe','failed')");
        dbCheck((int) $pdo->query('SELECT COUNT(*) FROM ce_migrations')->fetchColumn() === $originalCount + 1, "$driver: uncommitted insert not visible");
    } finally {
        $pdo->rollBack();
    }
    dbCheck((int) $pdo->query('SELECT COUNT(*) FROM ce_migrations')->fetchColumn() === $originalCount, "$driver: database rollback failed");
    echo "OK: $label applied $expected real migrations, re-run clean, schema integrity and transactional rollback\n";
}

$connections = [
    'sqlite' => ['sqlite', 'sqlite::memory:', null, null],
    'postgres17' => ['pgsql', 'pgsql:host=127.0.0.1;port=5432;dbname=cajeer_smoke', 'cajeer', 'release-smoke'],
    'postgres18' => ['pgsql', 'pgsql:host=127.0.0.1;port=5433;dbname=cajeer_smoke', 'cajeer', 'release-smoke'],
    'mariadb1011' => ['mysql', 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', 'migration-fixture'],
    'mysql84' => ['mysql', 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', 'migration-fixture'],
];
foreach ($connections as $label => [$driver, $dsn, $user, $password]) {
    dbCheck(in_array($driver, PDO::getAvailableDrivers(), true), "Missing pdo_$driver extension");
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $schema = 'ce_db_compat_' . bin2hex(random_bytes(5));
    if ($driver === 'pgsql') {
        $pdo->exec('CREATE SCHEMA ' . $schema);
        try {
            $pdo->exec('SET search_path TO ' . $schema);
            exercise($pdo, $driver, $root, $label);
        } finally {
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    } elseif ($driver === 'mysql') {
        $pdo->exec('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $pdo->exec('USE ' . $schema);
            exercise($pdo, $driver, $root, $label);
        } finally {
            $pdo->exec('DROP DATABASE ' . $schema);
        }
    } else {
        $pdo->exec('PRAGMA foreign_keys = ON');
        exercise($pdo, $driver, $root, $label);
    }
}
