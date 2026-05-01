<?php

declare(strict_types=1);

namespace Cajeer\Http\Middleware;

use Cajeer\Config\ConfigRepository;
use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
use Cajeer\Modules\Auth\Rbac\PermissionRepository;
use Cajeer\Modules\Auth\SessionGuard;

final class AdminAuthMiddleware
{
    public function __construct(private readonly Container $container, private readonly string $basePath) {}

    public function handle(Request $request, callable $next): Response
    {
        if (!$request->is('admin') && !$request->is('admin/*')) {
            return $next($request);
        }
        if ($request->is('admin/login') || $request->is('admin/logout')) {
            return $next($request);
        }
        if (!is_file($this->basePath . '/storage/installed.lock')) {
            return Response::redirect('/install');
        }

        $db = $this->container->get(DatabaseManager::class);
        $guard = new SessionGuard($db, config: $this->container->get(ConfigRepository::class));
        if (!$guard->check()) {
            return Response::redirect('/admin/login');
        }
        $userId = $guard->id();
        if ($userId === null || !(new PermissionRepository($db))->userHas($userId, 'admin.access')) {
            return $request->wantsJson()
                ? Response::json(['message' => 'Недостаточно прав.'], 403)
                : Response::html('<h1>403</h1><p>Недостаточно прав.</p>', 403);
        }

        return $next($request);
    }
}
