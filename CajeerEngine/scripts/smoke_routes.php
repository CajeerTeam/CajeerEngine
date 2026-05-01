<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/bootstrap.php';

use Cajeer\Http\Request;
use Cajeer\Kernel\Application;

$app = Application::create($root);
$routes = ['/', '/about', '/team', '/projects', '/projects/nevermine', '/support', '/brand', '/sitemap.xml', '/robots.txt', '/manifest.webmanifest', '/install', '/api/health', '/api/version', '/marketplace', '/admin'];
foreach ($routes as $route) {
    $response = $app->handle(new Request('GET', $route, [], [], ['HTTP_ACCEPT' => 'text/html']));
    $status = $response->status();
    if ($status >= 500) {
        fwrite(STDERR, "{$route}: {$status}\n");
        exit(1);
    }
    echo "{$route}: {$status}\n";
}
