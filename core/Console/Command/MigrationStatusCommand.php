<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:status', description: 'Показать применённые, ожидающие и сбойные миграции.')]
final class MigrationStatusCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = new DatabaseManager(new ConfigRepository($this->rootPath));
        $runner = new MigrationRunner($database->connection(), $this->rootPath . '/migrations');
        $status = $runner->status($database->migrationDriver());

        $output->writeln('Driver: ' . $status['driver']);
        $output->writeln('Applied: ' . count($status['applied']));
        foreach ($status['applied'] as $migration) {
            $checksum = ($migration['checksum_ok'] ?? true) ? 'checksum ok' : 'checksum changed';
            $output->writeln(sprintf('  [OK] %s batch=%s %s', $migration['migration'], $migration['batch'] ?? '-', $checksum));
        }

        $output->writeln('Pending: ' . count($status['pending']));
        foreach ($status['pending'] as $migration) {
            $output->writeln('  [PENDING] ' . $migration['migration']);
        }

        $output->writeln('Failed: ' . count($status['failed']));
        foreach ($status['failed'] as $migration) {
            $output->writeln('  [FAILED] ' . $migration['migration'] . ' ' . ($migration['error'] ?? ''));
        }

        return count($status['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
