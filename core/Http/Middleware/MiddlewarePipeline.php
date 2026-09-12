<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class MiddlewarePipeline
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(private array $middleware)
    {
    }

    /** @param callable(Request): Response $destination */
    public function handle(Request $request, callable $destination): Response
    {
        $next = array_reduce(
            array_reverse($this->middleware),
            static fn (callable $next, MiddlewareInterface $middleware): callable => static fn (Request $request): Response => $middleware->process($request, $next),
            $destination,
        );

        return $next($request);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (MiddlewareInterface $middleware): string => $middleware::class, $this->middleware);
    }
}
