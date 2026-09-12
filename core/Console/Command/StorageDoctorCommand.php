<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'storage:doctor', description: 'Проверяет Local/S3 storage и публичные uploads.')]
final class StorageDoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('storage:doctor')->setDescription('Проверяет Local/S3 storage и публичные uploads.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storage = new StorageManager($this->rootPath, new ConfigRepository($this->rootPath));
        $diagnostics = $storage->diagnostics();
        $output->writeln(json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return ($diagnostics['local_probe_write'] ?? false) && ($diagnostics['local_probe_delete'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
