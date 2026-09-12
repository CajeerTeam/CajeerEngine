<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

final readonly class OpenSearchEngine implements SearchEngineInterface
{
    public function __construct(private string $host, private int $timeout = 5)
    {
    }

    public function search(string $query, ?string $index = null, int $limit = 20): array
    {
        $target = rawurlencode($index ?: 'content');
        $result = $this->request('POST', '/' . $target . '/_search', [
            'size' => max(1, $limit),
            'query' => [
                'multi_match' => [
                    'query' => $query,
                    'fields' => ['title^3', 'body', 'excerpt', 'raw.*'],
                ],
            ],
        ]);
        $hits = is_array($result['hits']['hits'] ?? null) ? $result['hits']['hits'] : [];
        return array_values(array_map(static function (array $hit): array {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $source['id'] = (string) ($hit['_id'] ?? ($source['id'] ?? ''));
            $source['score'] = (float) ($hit['_score'] ?? 0);
            return $source;
        }, $hits));
    }

    public function index(string $index, string $id, array $document): void
    {
        $this->request('PUT', '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id), $document);
    }

    public function delete(string $index, string $id): void
    {
        $this->request('DELETE', '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id));
    }

    public function diagnostics(): array
    {
        try {
            $health = $this->request('GET', '/_cluster/health');
            return ['driver' => 'opensearch', 'host' => $this->host, 'healthy' => isset($health['status']), 'health' => $health];
        } catch (\Throwable $e) {
            return ['driver' => 'opensearch', 'host' => $this->host, 'healthy' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if ($this->host === '') {
            throw new \RuntimeException('OPENSEARCH_HOST не настроен.');
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => 'Content-Type: application/json',
            'content' => $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(rtrim($this->host, '/') . $path, false, $context);
        if (!is_string($body)) {
            throw new \RuntimeException('OpenSearch request failed: ' . $method . ' ' . $path);
        }
        $decoded = $body === '' ? [] : json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenSearch returned invalid JSON.');
        }
        if (isset($decoded['error'])) {
            $error = is_array($decoded['error']) ? ($decoded['error']['reason'] ?? json_encode($decoded['error'])) : $decoded['error'];
            throw new \RuntimeException('OpenSearch error: ' . (string) $error);
        }
        return $decoded;
    }
}
