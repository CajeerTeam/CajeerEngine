<?php

declare(strict_types=1);

namespace Cajeer\Routing;

use Cajeer\Container\Container;
use Cajeer\Http\Exceptions\NotFoundException;
use Cajeer\Http\Request;
use Cajeer\Http\Response;

final class Router
{
    /** @var array<string,array<string,callable|array|string>> */
    private array $routes = [];

    public function get(string $path, callable|array|string $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, callable|array|string $handler): void { $this->add('POST', $path, $handler); }
    public function put(string $path, callable|array|string $handler): void { $this->add('PUT', $path, $handler); }
    public function patch(string $path, callable|array|string $handler): void { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, callable|array|string $handler): void { $this->add('DELETE', $path, $handler); }

    public function add(string $method, string $path, callable|array|string $handler): void
    {
        $this->routes[strtoupper($method)][$this->normalize($path)] = $handler;
    }

    public function dispatch(Request $request, Container $container): Response
    {
        $methodRoutes = $this->routes[$request->method] ?? [];
        $path = $this->normalize($request->uri);

        if (isset($methodRoutes[$path])) {
            return $this->invoke($methodRoutes[$path], $request, $container);
        }

        foreach ($methodRoutes as $pattern => $handler) {
            $params = $this->matchPattern($pattern, $path);
            if ($params !== null) {
                $request = new Request($request->method, $request->uri, $request->query + $params, $request->post, $request->server, $request->files, $request->cookies);
                return $this->invoke($handler, $request, $container);
            }
        }

        throw new NotFoundException();
    }

    private function invoke(callable|array|string $handler, Request $request, Container $container): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = new $class($container);
            return $this->normalizeResult($instance->$method($request));
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $instance = new $class($container);
            return $this->normalizeResult($instance->$method($request));
        }

        return $this->normalizeResult($handler($request, $container));
    }

    private function normalizeResult(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** @return array<string,string>|null */
    private function matchPattern(string $pattern, string $path): ?array
    {
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function ($matches) use (&$paramNames): string {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $pattern);

        if ($regex === null || !preg_match('#^' . $regex . '$#', $path, $matches)) {
            return null;
        }

        array_shift($matches);
        return array_combine($paramNames, array_map('urldecode', $matches)) ?: [];
    }
}
