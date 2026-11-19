<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Scheduler\SchedulerRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class SchedulerController
{
    public function __construct(private SchedulerRunner $runner)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function tasks(Request $request, array $parameters): JsonResponse
    {
        $tasks = $this->runner->tasks();
        return new JsonResponse(['data' => $tasks, 'meta' => ['total' => count($tasks)]]);
    }

    /** @param array<string, mixed> $parameters */
    public function run(Request $request, array $parameters): JsonResponse
    {
        $result = $this->runner->run();
        return new JsonResponse(['data' => $result], ($result['ok'] ?? false) ? 200 : 503,
            ($result['ok'] ?? false) ? [] : ['Retry-After' => '5', 'Cache-Control' => 'no-store']);
    }
}
