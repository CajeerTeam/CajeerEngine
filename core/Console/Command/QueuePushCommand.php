<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:push', description: 'Добавить задачу в file-based очередь.')]
final class QueuePushCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Имя задачи');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Очередь', 'default');
        $this->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON payload', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = json_decode((string) $input->getOption('payload'), true);
        if (!is_array($payload)) {
            $output->writeln('<error>payload должен быть JSON-объектом.</error>');
            return self::FAILURE;
        }
        $id = $this->queue()->push((string) $input->getArgument('name'), $payload, (string) $input->getOption('queue'));
        $output->writeln('[OK] Задача добавлена: ' . $id);
        return self::SUCCESS;
    }

    private function queue(): \CajeerEngine\Queue\ManagedQueueDriverInterface
    {
        $config = new ConfigRepository($this->rootPath);
        return (new QueueFactory($this->rootPath, $config, new DatabaseManager($config)))->make();
    }
}
