<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:failed', description: 'Показать failed-задачи file-based очереди.')]
final class QueueFailedCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Очередь', 'default');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = $this->queue()->failed((string) $input->getOption('queue'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => ['total' => count($items)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln(sprintf('%s %s attempts=%d error=%s', (string) ($item['id'] ?? ''), (string) ($item['name'] ?? ''), (int) ($item['attempts'] ?? 0), (string) ($item['last_error'] ?? '')));
        }
        $output->writeln('[OK] Failed-задач: ' . count($items));
        return self::SUCCESS;
    }

    private function queue(): \CajeerEngine\Queue\ManagedQueueDriverInterface
    {
        $config = new ConfigRepository($this->rootPath);
        return (new QueueFactory($this->rootPath, $config, new DatabaseManager($config)))->make();
    }
}
