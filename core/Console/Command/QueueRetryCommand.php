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

#[AsCommand(name: 'queue:retry', description: 'Вернуть failed-задачу в pending.')]
final class QueueRetryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'ID failed-задачи');
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Очередь', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ok = $this->queue()->retry((string) $input->getArgument('id'), (string) $input->getOption('queue'));
        if (!$ok) {
            $output->writeln('<error>Failed-задача не найдена.</error>');
            return self::FAILURE;
        }
        $output->writeln('[OK] Задача возвращена в pending.');
        return self::SUCCESS;
    }

    private function queue(): \CajeerEngine\Queue\ManagedQueueDriverInterface
    {
        $config = new ConfigRepository($this->rootPath);
        return (new QueueFactory($this->rootPath, $config, new DatabaseManager($config)))->make();
    }
}
