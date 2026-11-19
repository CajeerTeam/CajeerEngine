<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Queue\QueueOptions;
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
        try {
            return new JsonResponse(['data' => $this->queue->diagnostics()]);
        } catch (\Throwable) {
            return $this->unavailable();
        }
    }

    /** @param array<string, mixed> $parameters */
    public function push(Request $request, array $parameters): JsonResponse
    {
        $body = $this->json($request);
        $name = (string) ($body['name'] ?? 'log.message');
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $queue = QueueOptions::queue((string) ($body['queue'] ?? 'default'));
        // Validate request data outside the transport catch: client errors remain 422.
        QueueOptions::id($queue, $name, $payload);
        QueueOptions::available($payload);
        try {
            $id = $this->queue->push($name, $payload, $queue);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Idempotency key reused with different job')) {
                return new JsonResponse(['error' => ['code' => 'queue_job_conflict', 'message' => 'Idempotency key already used for a different job.']], Response::HTTP_CONFLICT);
            }
            return $this->unavailable();
        } catch (\Throwable) {
            return $this->unavailable();
        }
        return new JsonResponse(['data' => ['id' => $id]], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $parameters */
    public function work(Request $request, array $parameters): JsonResponse
    {
        $body = $this->json($request);
        $queue = QueueOptions::queue((string) ($body['queue'] ?? 'default'));
        $result = $this->worker->work($queue, (int) ($body['limit'] ?? 10));
        return new JsonResponse(['data' => $result], ($result['backend_unavailable'] ?? false) ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
            ($result['backend_unavailable'] ?? false) ? ['Retry-After' => '5', 'Cache-Control' => 'no-store'] : []);
    }

    /** @param array<string, mixed> $parameters */
    public function failed(Request $request, array $parameters): JsonResponse
    {
        $queue = QueueOptions::queue((string) $request->query->get('queue', 'default'));
        try {
            $items = $this->queue->failed($queue);
        } catch (\Throwable) {
            return $this->unavailable();
        }
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    /** @param array<string, mixed> $parameters */
    public function retry(Request $request, array $parameters): JsonResponse
    {
        $queue = QueueOptions::queue((string) $request->query->get('queue', 'default'));
        $id = (string) ($parameters['id'] ?? '');
        QueueOptions::validateId($id);
        try {
            $ok = $this->queue->retry($id, $queue);
        } catch (\Throwable) {
            return $this->unavailable();
        }
        if (!$ok) return new JsonResponse(['error' => ['code' => 'queue_job_not_found', 'message' => 'Failed-задача не найдена.']], 404);
        return new JsonResponse(['data' => ['retried' => true]]);
    }

    private function unavailable(): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'queue_unavailable', 'message' => 'Очередь временно недоступна.']], Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '5', 'Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) return $parsed;
        $body = trim($request->getContent());
        if ($body === '') return [];
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        return is_array($payload) ? $payload : [];
    }
}
