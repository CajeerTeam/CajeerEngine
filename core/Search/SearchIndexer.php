<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;

final readonly class SearchIndexer
{
    public function __construct(
        private ContentTypeRepository $types,
        private ContentEntryRepository $entries,
        private SearchEngineInterface $index,
    ) {
    }

    /** @return array<string, mixed> */
    public function indexAll(): array
    {
        return $this->engine()->rebuild();
    }

    /** @param array<string, mixed> $entry */
    public function indexEntry(string $typeHandle, array $entry): void
    {
        // Compatibility entry point: derive from committed CMS state, never caller-supplied data.
        $this->engine()->rebuild();
    }

    public function reconcile(): array {return $this->engine()->ensure();}

    private function engine(): CmsSearchEngine
    {
        if($this->index instanceof CmsSearchEngine)return $this->index;
        $root=$this->entries->rootPath();$config=new \CajeerEngine\Runtime\ConfigRepository($root);
        return new CmsSearchEngine($root,new \CajeerEngine\Database\DatabaseManager($config),$config->string('search.default','file'));
    }
}
