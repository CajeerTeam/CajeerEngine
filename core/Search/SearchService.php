<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

final readonly class SearchService
{
    public function __construct(private SearchEngineInterface $index, private SearchIndexer $indexer)
    {
    }

    /** @return array<string, mixed> */
    public function search(string $query, ?string $index = null, int $limit = 20,int $offset=0,?string $type=null,?string $locale=null): array
    {
        if(!$this->index instanceof CmsSearchEngine)throw new \LogicException('CMS search requires authoritative catalog');
        return $this->index->page($query,$index,$limit,$offset,$type,$locale);
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
