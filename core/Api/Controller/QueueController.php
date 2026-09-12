<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Queue\QueueWorker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class QueueController
{
    public function __construct(private ManagedQueueDriverInterface $queue, private QueueWorker $worker)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->queue->diagnostics()]);
    }

    /** @param array<string, mixed> $parameters */
    public function push(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $id = $this->queue->push((string) ($payload['name'] ?? 'log.message'), is_array($payload['payload'] ?? null) ? $payload['payload'] : [], (string) ($payload['queue'] ?? 'default'));
        return new JsonResponse(['data' => ['id' => $id]], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $parameters */
    public function work(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        return new JsonResponse(['data' => $this->worker->work((string) ($payload['queue'] ?? 'default'), (int) ($payload['limit'] ?? 10))]);
    }

    /** @param array<string, mixed> $parameters */
    public function failed(Request $request, array $parameters): JsonResponse
    {
        $items = $this->queue->failed((string) $request->query->get('queue', 'default'));
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    /** @param array<string, mixed> $parameters */
    public function retry(Request $request, array $parameters): JsonResponse
    {
        $ok = $this->queue->retry((string) ($parameters['id'] ?? ''), (string) $request->query->get('queue', 'default'));
        if (!$ok) {
            return new JsonResponse(['error' => ['code' => 'queue_job_not_found', 'message' => 'Failed-задача не найдена.']], 404);
        }
        return new JsonResponse(['data' => ['retried' => true]]);
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) {
            return $parsed;
        }
        $body = trim($request->getContent());
        if ($body === '') {
            return [];
        }
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        return is_array($payload) ? $payload : [];
    }
}
