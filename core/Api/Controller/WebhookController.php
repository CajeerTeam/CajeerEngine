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
        return new JsonResponse(['data' => array_map(self::publicRecord(...), $this->webhooks->all()), 'meta' => $this->webhooks->diagnostics()], 200, ['Cache-Control'=>'no-store']);
    }

    /** @param array<string, mixed> $parameters */
    public function store(Request $request, array $parameters): JsonResponse
    {
        // A newly created secret is shown once. It cannot be fetched on subsequent reads.
        return new JsonResponse(['data' => $this->webhooks->create($this->json($request))], Response::HTTP_CREATED, ['Cache-Control'=>'no-store']);
    }

    /** @param array<string, mixed> $parameters */
    public function update(Request $request, array $parameters): JsonResponse
    {
        $item = $this->webhooks->update((string) ($parameters['id'] ?? ''), $this->json($request));
        if ($item === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => self::publicRecord($item)], 200, ['Cache-Control'=>'no-store']);
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
        return new JsonResponse(['data' => $result], 200, ['Cache-Control'=>'no-store']);
    }

    /** @param array<string, mixed> $parameters */
    public function deliveries(Request $request, array $parameters): JsonResponse
    {
        $webhookId = (string) $request->query->get('webhook_id', '');
        $items = $this->webhooks->deliveries($webhookId !== '' ? $webhookId : null);
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items)]], 200, ['Cache-Control'=>'no-store']);
    }

    /** @param array<string, mixed> $parameters */
    public function dispatch(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $event = (string) ($payload['event'] ?? 'manual.dispatch');
        $body = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $id=$this->webhooks->pushOutbox($event,$body);
        return new JsonResponse(['data'=>['event'=>$event,'event_id'=>$id,'queued'=>true,'deliveries'=>0,'items'=>[]]]);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private static function publicRecord(array $item): array
    {
        $item['secret_configured'] = is_string($item['secret'] ?? null) && $item['secret'] !== '';
        unset($item['secret']);
        if (is_string($item['url'] ?? null)) {
            $item['url_has_query'] = parse_url($item['url'], PHP_URL_QUERY) !== null;
            $item['url'] = preg_replace('/[?#].*$/s', '', $item['url']);
        }
        return $item;
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
