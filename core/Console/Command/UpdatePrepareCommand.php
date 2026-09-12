<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update:prepare', description: 'Сохранить план обновления в storage/app/updates/plans.')]
final class UpdatePrepareCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Локальный путь или URL ZIP artifact.')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Ожидаемая SHA256-сумма.')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Целевая версия.')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Тип artifact.', 'dist')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Не планировать backup.')
            ->addOption('no-maintenance', null, InputOption::VALUE_NONE, 'Не планировать maintenance mode.')
            ->addOption('no-migrations', null, InputOption::VALUE_NONE, 'Не планировать post-update migrations.')
            ->addOption('no-doctor', null, InputOption::VALUE_NONE, 'Не планировать post-update doctor.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new UpdateManager($this->rootPath, new ConfigRepository($this->rootPath)))->prepare([
            'package' => $input->getOption('package'),
            'sha256' => $input->getOption('sha256'),
            'version' => $input->getOption('version'),
            'mode' => $input->getOption('mode'),
            'no_backup' => (bool) $input->getOption('no-backup'),
            'no_maintenance' => (bool) $input->getOption('no-maintenance'),
            'no_migrations' => (bool) $input->getOption('no-migrations'),
            'no_doctor' => (bool) $input->getOption('no-doctor'),
        ]);
        $output->writeln((bool) $input->getOption('json') ? (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '[OK] План обновления: ' . (string) ($result['plan_file'] ?? ''));
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
