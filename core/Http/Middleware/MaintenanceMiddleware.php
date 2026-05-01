<?php

declare(strict_types=1);

namespace Cajeer\Http\Middleware;

use Cajeer\Http\Request;
use Cajeer\Http\Response;

final class MaintenanceMiddleware
{
    public function __construct(private readonly string $basePath) {}

    public function handle(Request $request, callable $next): Response
    {
        if (is_file($this->basePath . '/storage/framework/down')) {
            return Response::html('<h1>Техническое обслуживание</h1><p>Сайт временно недоступен.</p>', 503);
        }

        return $next($request);
    }
}
