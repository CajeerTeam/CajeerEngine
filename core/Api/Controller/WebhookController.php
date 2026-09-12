<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class WebhookController
{
    public function __construct(private WebhookRepository $webhooks, private WebhookDispatcher $dispatcher)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->webhooks->all(), 'meta' => $this->webhooks->diagnostics()]);
    }

    /** @param array<string, mixed> $parameters */
    public function store(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->webhooks->create($this->json($request))], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $parameters */
    public function update(Request $request, array $parameters): JsonResponse
    {
        $item = $this->webhooks->update((string) ($parameters['id'] ?? ''), $this->json($request));
        if ($item === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $item]);
    }

    /** @param array<string, mixed> $parameters */
    public function delete(Request $request, array $parameters): JsonResponse
    {
        if (!$this->webhooks->delete((string) ($parameters['id'] ?? ''))) {
            return $this->notFound();
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<string, mixed> $parameters */
    public function test(Request $request, array $parameters): JsonResponse
    {
        $webhook = $this->webhooks->find((string) ($parameters['id'] ?? ''));
        if ($webhook === null) {
            return $this->notFound();
        }
        $result = $this->dispatcher->dispatchWebhook($webhook, 'webhook.test', ['message' => 'CajeerEngine webhook test']);
        return new JsonResponse(['data' => $result]);
    }

    /** @param array<string, mixed> $parameters */
    public function deliveries(Request $request, array $parameters): JsonResponse
    {
        $webhookId = (string) $request->query->get('webhook_id', '');
        $items = $this->webhooks->deliveries($webhookId !== '' ? $webhookId : null);
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]]);
    }

    /** @param array<string, mixed> $parameters */
    public function dispatch(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $event = (string) ($payload['event'] ?? 'manual.dispatch');
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        return new JsonResponse(['data' => $this->dispatcher->dispatchEvent($event, $body)]);
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
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body должен быть объектом.');
        }
        return $payload;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'webhook_not_found', 'message' => 'Webhook не найден.']], Response::HTTP_NOT_FOUND);
    }
}
