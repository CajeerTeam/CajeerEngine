<?php

declare(strict_types=1);

namespace Cajeer\Http\Middleware;

use Cajeer\Container\Container;
use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Request;
use Cajeer\Http\Response;
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

        $guard = new SessionGuard($this->container->get(DatabaseManager::class));
        if (!$guard->check()) {
            return Response::redirect('/admin/login');
        }

        return $next($request);
    }
}
