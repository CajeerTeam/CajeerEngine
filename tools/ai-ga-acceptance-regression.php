#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real GA diagnostics acceptance. Uses a SQL database if available; never calls a provider. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'CajeerEngine\\')) return;
    $path = $root.'/core/'.str_replace('\\', '/', substr($class, strlen('CajeerEngine\\'))).'.php';
    if (is_file($path)) require_once $path;
});
use CajeerEngine\AI\AiGaAcceptance;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$check = static function (bool $valid, string $label): void {
    if (!$valid) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS '.$label."\n";
};
$cfg = new ConfigRepository($root);
$cfg->set('database.default', 'sqlite');
$cfg->set('database.connections.sqlite.database', ':memory:');
$service = new AiGaAcceptance($root, $cfg, new DatabaseManager($cfg));
$existing = getenv('OPENAI_API_KEY');
try {
    putenv('OPENAI_API_KEY=sk-test-ga-012345678901234567890123456789');
    $report = $service->run();
    $checks = array_column($report['checks'], 'status', 'check');
    $check($report['version'] === trim((string)file_get_contents($root.'/VERSION')), 'GA version is the actual release version');
    $check($checks['release_version'] === 'pass' && $checks['admin_assets'] === 'pass', 'GA release and published admin assets match');
    $check($checks['openai_configuration'] === 'pass' && $checks['credential_decryption'] === 'pass', 'Credential readiness verifies decryption without exposing key');
    $check(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'sk-test-ga-'), 'Acceptance never logs provider secrets');
    $check(!isset($checks['live_provider']), 'External provider is never called without explicit opt-in');
    $strict = $service->run(true);
    $strictChecks = array_column($strict['checks'], 'status', 'check');
    $check($strictChecks['production_environment'] === 'fail', 'Production requirement fails closed on development configuration');
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        if (in_array('--require-db', $argv, true)) throw new RuntimeException('pdo_sqlite required for GA acceptance');
        $check($checks['database_connectivity'] === 'fail' && $checks['ai_sql_schema'] === 'fail', 'Missing PDO driver is explicitly reported as an acceptance failure');
        echo 'SKIP SQL fixtures: no pdo_sqlite\n';
        exit(0);
    }
    // Exercise the exact 1.9.x migration chain, not the obsolete 0031-0038 subset.
    $db = new DatabaseManager($cfg);
    $connection = $db->connection();
    $connection->exec('PRAGMA foreign_keys = ON');
    (new \CajeerEngine\Database\MigrationRunner($connection, $root.'/migrations'))->run('sqlite');
    $service = new AiGaAcceptance($root, $cfg, $db);
    $healthy = $service->run(); $healthyChecks = array_column($healthy['checks'], 'status', 'check');
    $check($healthyChecks['database_connectivity'] === 'pass' && $healthyChecks['ai_sql_schema'] === 'pass', 'All real migrated AI tables pass readiness');
    $check($healthy['ok'] === true, 'Complete GA offline preflight succeeds in a fully migrated environment');
    $connection->exec('DROP TABLE ce_support_messages');
    $broken = $service->run();
    $check(!$broken['ok'] && array_column($broken['checks'], 'status', 'check')['ai_sql_schema'] === 'fail', 'Missing Support migration blocks GA acceptance');
    echo "AI GA acceptance integration PASSED\n";
} finally {
    putenv('OPENAI_API_KEY='.($existing === false ? '' : $existing));
}
