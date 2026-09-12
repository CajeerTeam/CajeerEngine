<?php

declare(strict_types=1);

$root = __DIR__;
$lock = $root . '/storage/app/installed.lock';
if (is_file($lock)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CajeerEngine уже установлен. Installer отключён.\n";
    exit;
}

require $root . '/public/install/index.php';
