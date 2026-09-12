<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

final readonly class SearchFactory
{
    public function __construct(private string $rootPath, private ConfigRepository $config, private ?DatabaseManager $database = null)
    {
    }

    public function make(): SearchEngineInterface
    {
        $driver = strtolower($this->config->string('search.default', 'file'));
        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => new PostgresFullTextSearchEngine($this->database()->connection(), $this->config->string('search.engines.pgsql.language', 'russian')),
            'meili', 'meilisearch' => new MeilisearchEngine($this->config->string('search.engines.meilisearch.host', ''), $this->config->string('search.engines.meilisearch.key', ''), $this->config->int('updates.core.timeout', 5)),
            'opensearch' => new OpenSearchEngine($this->config->string('search.engines.opensearch.host', ''), $this->config->int('updates.core.timeout', 5)),
            default => new FileSearchIndex($this->rootPath),
        };
    }

    private function database(): DatabaseManager
    {
        return $this->database ?? new DatabaseManager($this->config);
    }
}
