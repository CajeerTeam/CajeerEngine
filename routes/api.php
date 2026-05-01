<?php

use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Response;
use Cajeer\Modules\Content\Repository\ContentRepository;
use Cajeer\System\HealthChecker;
use Cajeer\System\MetricsCollector;

$router->get('/api/health', static function ($request, $container) {
    $basePath = dirname(__DIR__);
    return (new HealthChecker($container, $basePath))->summary();
});

$router->get('/api/health/db', static function ($request, $container) {
    try {
        $container->get(DatabaseManager::class)->connection()->query('SELECT 1');
        return ['status' => 'ok'];
    } catch (Throwable $e) {
        return Response::json(['status' => 'error', 'message' => $e->getMessage()], 503);
    }
});

$router->get('/api/version', static fn () => ['version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '0.1.0-dev']);

$router->get('/api/content', static function ($request, $container) {
    $repo = new ContentRepository($container->get(DatabaseManager::class));
    return ['data' => array_map(static fn ($item) => (array) $item, $repo->latest((string) $request->input('type', 'post'), (int) $request->input('limit', 20)))];
});

$router->get('/api/content/{id}', static function ($request, $container) {
    $repo = new ContentRepository($container->get(DatabaseManager::class));
    $item = $repo->find((int) $request->input('id'));
    return $item ? ['data' => (array) $item] : Response::json(['error' => 'not_found'], 404);
});

$router->get('/api/metrics', static function () {
    return new Response((new MetricsCollector())->collect(dirname(__DIR__)), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8']);
});
