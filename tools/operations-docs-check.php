#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

try {
    $checks = array_map(static fn ($c) => $c->toArray(), (new CajeerEngine\Rc\OperationsDocsVerifier($root))->verify());
    $failures = array_values(array_filter($checks, static fn ($c) => $c['status'] === 'fail'));
    if (in_array('--json', $argv, true)) {
        echo json_encode(['ok' => $failures === [], 'checked' => count($checks), 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        printf("Operational documentation: %d checks, %d failures\n", count($checks), count($failures));
        foreach ($failures as $check) printf("[FAIL] %s: %s\n", $check['name'], $check['message']);
    }
    exit($failures === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Runbook verification failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
