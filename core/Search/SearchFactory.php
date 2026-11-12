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

    public function make(): CmsSearchEngine
    {
        $driver = strtolower($this->config->string('search.default', 'file'));
        return new CmsSearchEngine($this->rootPath,$this->database(),$driver);
    }

    private function database(): DatabaseManager
    {
        return $this->database ?? new DatabaseManager($this->config);
    }
}
