<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Scheduler\SchedulerRunner;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Search\SearchIndexer;
use CajeerEngine\Security\SignedPayload;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'schedule:run', description: 'Выполнить реальные плановые задачи CajeerEngine.')]
final class SchedulerRunCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->runner()->run();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($result['tasks'] ?? [] as $name => $data) {
            $output->writeln('[OK] ' . $name . ': ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return self::SUCCESS;
    }

    private function runner(): SchedulerRunner
    {
        $config = new ConfigRepository($this->rootPath);
        $db = new DatabaseManager($config);
        $search = (new SearchFactory($this->rootPath, $config, $db))->make();
        $indexer = new SearchIndexer(new ContentTypeRepository($this->rootPath, $db), new ContentEntryRepository($this->rootPath, $db), $search);
        $webhooks = new WebhookRepository($this->rootPath);
        $queue = (new QueueFactory($this->rootPath, $config, $db))->make();
        return new SchedulerRunner($this->rootPath, $indexer, $webhooks, new WebhookDispatcher(new SignedPayload(), $webhooks), $queue);
    }
}
