<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Cajeer\\Compatibility\\WordPress\\' => __DIR__ . '/../compatibility/wordpress/',
        'Cajeer\\Compatibility\\Dle\\' => __DIR__ . '/../compatibility/dle/',
        'Cajeer\\Modules\\' => __DIR__ . '/../modules/',
        'Cajeer\\App\\' => __DIR__ . '/../app/',
        'Cajeer\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

require_once dirname(__DIR__) . '/compatibility/wordpress/functions.php';
