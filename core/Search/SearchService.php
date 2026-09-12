<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

final readonly class SearchService
{
    public function __construct(private SearchEngineInterface $index, private SearchIndexer $indexer)
    {
    }

    /** @return array<string, mixed> */
    public function search(string $query, ?string $index = null, int $limit = 20): array
    {
        $items = $this->index->search($query, $index, $limit);
        return [
            'data' => $items,
            'meta' => [
                'query' => $query,
                'index' => $index ?: 'all',
                'total' => count($items),
                'driver' => $this->index->diagnostics()['driver'] ?? 'unknown',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function reindex(): array
    {
        return $this->indexer->indexAll();
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        return $this->index->diagnostics();
    }
}
