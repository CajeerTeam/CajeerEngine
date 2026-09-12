<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update:apply', description: 'Применить локальный/URL ZIP artifact с предварительным backup.')]
final class UpdateApplyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Локальный путь или URL ZIP artifact.', null)
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Ожидаемая SHA256-сумма.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план без записи файлов.')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Не создавать backup перед update.')
            ->addOption('no-maintenance', null, InputOption::VALUE_NONE, 'Не включать maintenance mode во время apply.')
            ->addOption('no-migrations', null, InputOption::VALUE_NONE, 'Не запускать миграции после apply.')
            ->addOption('no-doctor', null, InputOption::VALUE_NONE, 'Не запускать doctor после apply.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Разрешить apply при неполной проверке package.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new UpdateManager($this->rootPath, new ConfigRepository($this->rootPath)))->apply([
            'package' => $input->getOption('package'),
            'sha256' => $input->getOption('sha256'),
            'dry_run' => (bool) $input->getOption('dry-run'),
            'no_backup' => (bool) $input->getOption('no-backup'),
            'no_maintenance' => (bool) $input->getOption('no-maintenance'),
            'no_migrations' => (bool) $input->getOption('no-migrations'),
            'no_doctor' => (bool) $input->getOption('no-doctor'),
            'force' => (bool) $input->getOption('force'),
        ]);
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(($result['dry_run'] ?? false) ? '[OK] Dry-run: файлы не изменены.' : '[OK] Update применён. Файлов обновлено: ' . count((array) ($result['applied_files'] ?? [])));
            if (is_array($result['backup'] ?? null) && isset($result['backup']['target'])) { $output->writeln('[OK] Backup: ' . (string) $result['backup']['target']); }
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
