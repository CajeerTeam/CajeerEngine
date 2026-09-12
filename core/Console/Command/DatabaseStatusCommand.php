<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:status', description: 'Показать состояние подключения к базе данных и основных таблиц.')]
final class DatabaseStatusCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = new DatabaseManager(new ConfigRepository($this->rootPath));
        $health = $database->health();
        $output->writeln('Driver: ' . ($health['driver'] ?? 'unknown'));
        $output->writeln('Database: ' . ($health['database'] ?? ''));
        $output->writeln('Status: ' . (($health['ok'] ?? false) ? 'OK' : 'FAIL'));
        $output->writeln('Latency: ' . ($health['latency_ms'] ?? '-') . ' ms');
        if (isset($health['server_version'])) {
            $output->writeln('Server: ' . $health['server_version']);
        }

        if (isset($health['error']) && is_array($health['error'])) {
            $output->writeln('[FAIL] ' . ($health['error']['message'] ?? 'unknown database error'));
            return self::FAILURE;
        }

        $output->writeln('Tables:');
        foreach (($health['tables'] ?? []) as $table => $exists) {
            $output->writeln(sprintf('  %s %s', $exists ? '[OK]' : '[MISSING]', $table));
        }

        return empty($health['missing_tables'] ?? []) ? self::SUCCESS : self::FAILURE;
    }
}
