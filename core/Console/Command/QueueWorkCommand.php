<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Queue\QueueWorker;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Search\SearchIndexer;
use CajeerEngine\Security\SignedPayload;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Обработать задачи file-based очереди CajeerEngine.')]
final class QueueWorkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Очередь', 'default');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум задач за запуск', '10');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $worker = $this->worker();
        $result = $worker->work((string) $input->getOption('queue'), (int) $input->getOption('limit'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Обработано задач: ' . (int) ($result['processed'] ?? 0));
        $output->writeln('[OK] Ошибок: ' . (int) ($result['failed'] ?? 0));
        return ((int) ($result['failed'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function worker(): QueueWorker
    {
        $config = new ConfigRepository($this->rootPath);
        $db = new DatabaseManager($config);
        $queue = (new QueueFactory($this->rootPath, $config, $db))->make();
        $search = (new SearchFactory($this->rootPath, $config, $db))->make();
        $indexer = new SearchIndexer(new ContentTypeRepository($this->rootPath, $db), new ContentEntryRepository($this->rootPath, $db), $search);
        $webhooks = new WebhookRepository($this->rootPath);
        return new QueueWorker($queue, $indexer, $webhooks, new WebhookDispatcher(new SignedPayload(), $webhooks));
    }
}
