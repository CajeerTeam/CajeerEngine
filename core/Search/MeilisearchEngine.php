<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

final readonly class MeilisearchEngine implements SearchEngineInterface
{
    public function __construct(private string $host, private ?string $key = null, private int $timeout = 5)
    {
    }

    public function search(string $query, ?string $index = null, int $limit = 20): array
    {
        $payload = ['q' => $query, 'limit' => max(1, $limit)];
        $result = $this->request('POST', '/indexes/' . rawurlencode($index ?: 'content') . '/search', $payload);
        $hits = is_array($result['hits'] ?? null) ? $result['hits'] : [];
        return array_values(array_map(static fn (array $hit): array => $hit + ['score' => $hit['_rankingScore'] ?? null], $hits));
    }

    public function index(string $index, string $id, array $document): void
    {
        $document['id'] = $id;
        $this->request('POST', '/indexes/' . rawurlencode($index) . '/documents', [$document]);
    }

    public function delete(string $index, string $id): void
    {
        $this->request('DELETE', '/indexes/' . rawurlencode($index) . '/documents/' . rawurlencode($id));
    }

    public function diagnostics(): array
    {
        try {
            $health = $this->request('GET', '/health');
            return ['driver' => 'meilisearch', 'host' => $this->host, 'healthy' => ($health['status'] ?? null) === 'available', 'health' => $health];
        } catch (\Throwable $e) {
            return ['driver' => 'meilisearch', 'host' => $this->host, 'healthy' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $payload @return array<string, mixed> */
    private function request(string $method, string $path, array|null $payload = null): array
    {
        if ($this->host === '') {
            throw new \RuntimeException('MEILISEARCH_HOST не настроен.');
        }
        $headers = ['Content-Type: application/json'];
        if ($this->key !== null && $this->key !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(rtrim($this->host, '/') . $path, false, $context);
        if (!is_string($body)) {
            throw new \RuntimeException('Meilisearch request failed: ' . $method . ' ' . $path);
        }
        $decoded = $body === '' ? [] : json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Meilisearch returned invalid JSON.');
        }
        if (isset($decoded['message']) && isset($decoded['code'])) {
            throw new \RuntimeException('Meilisearch error: ' . (string) $decoded['message']);
        }
        return $decoded;
    }
}
