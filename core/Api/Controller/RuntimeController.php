<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Http\Router;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\ServiceContainer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class RuntimeController
{
    /** @param list<string> $middleware */
    public function __construct(
        private ConfigRepository $config,
        private ServiceContainer $container,
        private Router $router,
        private EventDispatcher $events,
        private array $middleware,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'config' => $this->config->safePublicConfig(),
                'services' => $this->container->describe(),
                'middleware' => $this->middleware,
                'routes' => $this->router->routes(),
                'events' => [
                    'listeners' => $this->events->listenerCounts(),
                    'dispatched' => $this->events->dispatched(),
                ],
            ],
        ]);
    }
}
