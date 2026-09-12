<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Extension\Runtime\ExtensionRuntime;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Runtime\ServiceContainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:migrate', description: 'Запустить file/PDO migrations установленных расширений.')]
final class ExtensionMigrateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Имя расширения vendor/name или slug');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать pending migrations без запуска');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->runtime()->migrate((string) ($input->getArgument('name') ?: '') ?: null, (bool) $input->getOption('dry-run'));
            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                foreach ((array) ($result['data'] ?? []) as $item) {
                    $output->writeln(sprintf('[OK] %s: run=%d pending_before=%d', (string) ($item['extension'] ?? ''), count((array) ($item['run'] ?? [])), (int) ($item['pending_before'] ?? 0)));
                }
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('[FAIL] ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function runtime(): ExtensionRuntime
    {
        $config = new ConfigRepository($this->rootPath);
        $container = new ServiceContainer();
        $events = new EventDispatcher(new RuntimeLogger($this->rootPath));
        return new ExtensionRuntime($this->rootPath, new ExtensionRegistry($this->rootPath, $config), $container, $config, $events, new RuntimeLogger($this->rootPath), new DatabaseManager($config));
    }
}
