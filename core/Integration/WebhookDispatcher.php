<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Support\Uuid;

final readonly class WebhookDispatcher
{
    public function __construct(
        private SignedPayload $signedPayload,
        private ?WebhookRepository $repository = null,
    ) {
    }

    /** @param array<string, mixed> $payload @return array{headers:array<string,string>,body:string} */
    public function buildSignedRequest(string $event, array $payload, string $secret): array
    {
        $body = json_encode([
            'event' => $event,
            'payload' => $payload,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-CajeerEngine-Event' => $event,
                'X-CajeerEngine-Signature' => $this->signedPayload->sign($body, $secret),
            ],
            'body' => $body,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function dispatchEvent(string $event, array $payload): array
    {
        if (!$this->repository instanceof WebhookRepository) {
            return ['event' => $event, 'deliveries' => 0, 'items' => []];
        }
        $items = [];
        foreach ($this->repository->matching($event) as $webhook) {
            $items[] = $this->dispatchWebhook($webhook, $event, $payload);
        }
        return ['event' => $event, 'deliveries' => count($items), 'items' => $items];
    }

    /** @param array<string, mixed> $webhook @param array<string, mixed> $payload @return array<string, mixed> */
    public function dispatchWebhook(array $webhook, string $event, array $payload): array
    {
        $started = microtime(true);
        $secret = (string) ($webhook['secret'] ?? '');
        $request = $this->buildSignedRequest($event, $payload, $secret);
        $statusCode = 0;
        $error = null;
        $responseBody = '';

        try {
            [$statusCode, $responseBody] = $this->send((string) $webhook['url'], $request['headers'], $request['body']);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $delivery = [
            'id' => Uuid::v4(),
            'webhook_id' => (string) ($webhook['id'] ?? ''),
            'webhook_name' => (string) ($webhook['name'] ?? ''),
            'event' => $event,
            'url' => (string) ($webhook['url'] ?? ''),
            'status_code' => $statusCode,
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'error' => $error,
            'response_excerpt' => function_exists('mb_substr') ? mb_substr($responseBody, 0, 500) : substr($responseBody, 0, 500),
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->repository?->recordDelivery($delivery);
        return $delivery;
    }

    /** @param array<string,string> $headers @return array{0:int,1:string} */
    private function send(string $url, array $headers, string $body): array
    {
        return (new \CajeerEngine\Security\PublicHttpClient())->request($url, 'POST', $headers, $body, 1048576);
    }
}
