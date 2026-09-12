#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'CajeerEngine\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

$json = in_array('--json', $argv, true);
$strict = in_array('--strict', $argv, true);
$output = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, 9);
    }
}

try {
    $result = (new CajeerEngine\Rc\ReleaseCandidateAuditor($root))->run();
    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (is_string($output) && $output !== '') {
        $path = str_starts_with($output, '/') ? $output : $root . '/' . $output;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $encoded . PHP_EOL, LOCK_EX);
    }
    if ($json) {
        echo $encoded . PHP_EOL;
    } else {
        $summary = $result['summary'];
        echo 'CajeerEngine CI check' . PHP_EOL;
        echo 'Ready: ' . ($result['ready'] ? 'yes' : 'no') . PHP_EOL;
        echo 'Checks: ' . $summary['total'] . ' total, ' . $summary['pass'] . ' pass, ' . $summary['warn'] . ' warn, ' . $summary['fail'] . ' fail' . PHP_EOL;
        foreach ($summary['groups'] as $group => $data) {
            echo sprintf('- %s: %d pass / %d warn / %d fail', $group, $data['pass'], $data['warn'], $data['fail']) . PHP_EOL;
        }
    }
    if (($result['summary']['fail'] ?? 0) > 0) {
        exit(1);
    }
    if ($strict && ($result['summary']['warn'] ?? 0) > 0) {
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
