<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:repair', description: 'Удалить failed-статусы миграций и опционально сразу повторить migrate.')]
final class MigrationRepairCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_NONE, 'После repair сразу выполнить migrate.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = new DatabaseManager(new ConfigRepository($this->rootPath));
        $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
        $result = $runner->repairFailed($database->migrationDriver(), (bool) $input->getOption('run'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Driver: ' . $result['driver']);
        $output->writeln('[OK] Failed migrations removed: ' . $result['removed']);
        foreach ($result['removed_migrations'] as $migration) {
            $output->writeln('  - ' . $migration);
        }
        if (isset($result['rerun'])) {
            $output->writeln('[OK] Re-run applied: ' . $result['rerun']['applied']);
        }
        return self::SUCCESS;
    }
}
