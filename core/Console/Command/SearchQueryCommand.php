<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\SearchFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:query', description: 'Поиск по публичному каталогу CMS.')]
final class SearchQueryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::REQUIRED, 'Поисковый запрос');
        $this->addOption('index', null, InputOption::VALUE_REQUIRED, 'Индекс', 'content');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Лимит', '20');
        $this->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Смещение', '0');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Тип контента');
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Язык');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->search()->page((string) $input->getArgument('query'), (string) $input->getOption('index'), (int) $input->getOption('limit'), (int) $input->getOption('offset'), $input->getOption('type'), $input->getOption('locale'));
        $items=$result['data'];
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln(sprintf('[%s] %s — %s', (string) ($item['score'] ?? 0), (string) ($item['title'] ?? ''), (string) ($item['url'] ?? '')));
        }
        $output->writeln('[OK] Результатов: ' . $result['meta']['total']);
        return self::SUCCESS;
    }

    private function search(): \CajeerEngine\Search\CmsSearchEngine
    {
        $config = new ConfigRepository($this->rootPath);
        return (new SearchFactory($this->rootPath, $config, new DatabaseManager($config)))->make();
    }
}
