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

#[AsCommand(name: 'schedule:due', description: 'Показать зарегистрированные плановые задачи.')]
final class SchedulerDueCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = $this->runner();
        $tasks = $runner->tasks();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $tasks, 'meta' => ['total' => count($tasks)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($tasks as $task) {
            $output->writeln(sprintf('%s [%s] — %s', (string) $task['name'], (string) $task['interval'], (string) $task['description']));
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
