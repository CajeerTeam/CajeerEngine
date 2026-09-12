<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface MiddlewareInterface
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response;
}
