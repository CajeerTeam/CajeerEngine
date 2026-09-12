<?php

declare(strict_types=1);

namespace CajeerEngine\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class Router
{
    private RouteCollection $routes;

    /** @var array<string, callable> */
    private array $controllers = [];

    /** @var list<array{name:string,path:string,methods:list<string>}> */
    private array $routeList = [];

    public function __construct()
    {
        $this->routes = new RouteCollection();
    }

    public function get(string $path, string $name, callable $controller): void
    {
        $this->add(['GET'], $path, $name, $controller);
    }

    public function post(string $path, string $name, callable $controller): void
    {
        $this->add(['POST'], $path, $name, $controller);
    }

    public function put(string $path, string $name, callable $controller): void
    {
        $this->add(['PUT'], $path, $name, $controller);
    }

    public function patch(string $path, string $name, callable $controller): void
    {
        $this->add(['PATCH'], $path, $name, $controller);
    }

    public function delete(string $path, string $name, callable $controller): void
    {
        $this->add(['DELETE'], $path, $name, $controller);
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $path, string $name, callable $controller): void
    {
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $requirements = [];
        if (str_contains($path, '{path}')) {
            $requirements['path'] = '.+';
        }
        $this->routes->add($name, new Route($path, ['_cajeer_controller' => $name], $requirements, [], '', [], $methods));
        $this->controllers[$name] = $controller;
        $this->routeList[] = [
            'name' => $name,
            'path' => $path,
            'methods' => $methods,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $context = new RequestContext();
        $context->fromRequest($request);
        $matcher = new UrlMatcher($this->routes, $context);

        try {
            $parameters = $matcher->match($request->getPathInfo());
            $controllerName = (string) $parameters['_cajeer_controller'];
            $controller = $this->controllers[$controllerName] ?? null;

            if (!is_callable($controller)) {
                return $this->error($request, 'route_controller_missing', 'Контроллер маршрута не найден.', 500);
            }

            $request->attributes->set('route_name', $controllerName);
            $request->attributes->set('route_parameters', $parameters);

            $response = $controller($request, $parameters);
            return $response instanceof Response ? $response : new JsonResponse($response);
        } catch (ResourceNotFoundException) {
            return $this->error($request, 'not_found', 'Маршрут не найден.', 404);
        } catch (MethodNotAllowedException $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'method_not_allowed',
                    'message' => 'Метод HTTP не разрешён для этого маршрута.',
                    'allowed' => $e->getAllowedMethods(),
                    'request_id' => (string) $request->attributes->get('request_id', ''),
                ],
            ], 405);
        }
    }

    /** @return list<array{name:string,path:string,methods:list<string>}> */
    public function routes(): array
    {
        return $this->routeList;
    }

    private function error(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => (string) $request->attributes->get('request_id', ''),
            ],
        ], $status);
    }
}
