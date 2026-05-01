<?php

use Cajeer\Config\ConfigRepository;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Response;
use Cajeer\Modules\Auth\Rbac\PermissionRepository;
use Cajeer\Modules\Auth\SessionGuard;
use Cajeer\Modules\Content\Repository\ContentRepository;
use Cajeer\System\HealthChecker;
use Cajeer\System\MetricsCollector;

$router->get('/api/health', static function ($request, $container) {
    return (new HealthChecker($container, dirname(__DIR__)))->summary();
});

$router->get('/api/health/db', static function ($request, $container) {
    try { $container->get(DatabaseManager::class)->connection()->query('SELECT 1'); return ['status' => 'ok']; }
    catch (Throwable $e) { return Response::json(['status' => 'error', 'message' => $e->getMessage()], 503); }
});

$router->get('/api/version', static fn () => ['version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '0.1.0-dev']);

$router->get('/api/content', static function ($request, $container) {
    $repo = new ContentRepository($container->get(DatabaseManager::class));
    $page = max(1, (int) $request->input('page', 1));
    $limit = max(1, min(100, (int) $request->input('limit', 20)));
    $type = (string) $request->input('type', 'post');
    $items = $repo->latest($type, $limit, ($page - 1) * $limit);
    $total = $repo->count($type);
    return ['data' => array_map(static fn ($item) => $item->toArray(), $items), 'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total]];
});

$router->get('/api/content/{id}', static function ($request, $container) {
    $item = (new ContentRepository($container->get(DatabaseManager::class)))->find((int) $request->input('id'));
    return $item ? ['data' => $item->toArray()] : Response::json(['error' => 'not_found'], 404);
});

$writeGuard = static function ($request, $container): ?Response {
    $db = $container->get(DatabaseManager::class);
    $guard = new SessionGuard($db, config: $container->get(ConfigRepository::class));
    $userId = $guard->id();
    if (!$userId || !(new PermissionRepository($db))->userHas($userId, 'content.write')) return Response::json(['message' => 'Unauthorized'], 401);
    return null;
};

$router->post('/api/content', static function ($request, $container) use ($writeGuard) {
    if ($r = $writeGuard($request, $container)) return $r;
    $id = (new ContentRepository($container->get(DatabaseManager::class)))->create($request->post);
    return ['data' => ['id' => $id]];
});
$router->put('/api/content/{id}', static function ($request, $container) use ($writeGuard) {
    if ($r = $writeGuard($request, $container)) return $r;
    (new ContentRepository($container->get(DatabaseManager::class)))->update((int) $request->input('id'), $request->post);
    return ['updated' => true];
});
$router->delete('/api/content/{id}', static function ($request, $container) use ($writeGuard) {
    if ($r = $writeGuard($request, $container)) return $r;
    (new ContentRepository($container->get(DatabaseManager::class)))->delete((int) $request->input('id'));
    return ['deleted' => true];
});

$router->get('/api/metrics', static function () {
    return new Response((new MetricsCollector())->collect(dirname(__DIR__)), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=UTF-8']);
});
