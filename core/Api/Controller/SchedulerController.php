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
        return new JsonResponse(['data' => $this->runner->tasks(), 'meta' => ['total' => count($this->runner->tasks())]]);
    }

    /** @param array<string, mixed> $parameters */
    public function run(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->runner->run()]);
    }
}
