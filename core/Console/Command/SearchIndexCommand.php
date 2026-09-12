<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Search\SearchIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:index', description: 'Переиндексировать опубликованный контент.')]
final class SearchIndexCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $db = new DatabaseManager($config);
        $search = (new SearchFactory($this->rootPath, $config, $db))->make();
        $indexer = new SearchIndexer(new ContentTypeRepository($this->rootPath, $db), new ContentEntryRepository($this->rootPath, $db), $search);
        $result = $indexer->indexAll();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Проиндексировано документов: ' . (int) ($result['indexed'] ?? 0));
        return self::SUCCESS;
    }
}
