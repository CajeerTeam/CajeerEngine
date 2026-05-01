<?php

declare(strict_types=1);

namespace Cajeer\Http;

final class MiddlewarePipeline
{
    /** @param array<int,object> $middlewares */
    public function __construct(private array $middlewares) {}

    public function handle(Request $request, callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            static fn (callable $next, object $middleware): callable => static fn (Request $request): Response => $middleware->handle($request, $next),
            $destination
        );

        return $pipeline($request);
    }
}
