<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate', description: 'Выполнить SQL-миграции для текущего DB driver. Статус хранится в ce_migrations.')]
final class MigrateCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = new DatabaseManager(new ConfigRepository($this->rootPath));
        $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
        $result = $runner->run($database->migrationDriver());

        $output->writeln('[OK] Driver: ' . $database->migrationDriver());
        $output->writeln('[OK] Batch: ' . $result['batch']);
        $output->writeln('[OK] Применено миграций: ' . $result['applied']);
        foreach ($result['migrations'] as $migration) {
            $output->writeln('  - ' . $migration['migration']);
        }

        return self::SUCCESS;
    }
}
