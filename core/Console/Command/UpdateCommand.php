<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update', description: 'Проверить или применить обновление CajeerEngine с backup-before-update.')]
final class UpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Локальный путь или URL ZIP artifact для применения обновления.')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Ожидаемая SHA256-сумма ZIP artifact.')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Целевая версия для плана обновления.')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Тип artifact: dist/source.', 'dist')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Применить package. Без этого команда только строит план.')
            ->addOption('prepare', null, InputOption::VALUE_NONE, 'Сохранить update plan в storage/app/updates/plans.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать apply plan без записи файлов.')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Не создавать backup перед update.')
            ->addOption('no-maintenance', null, InputOption::VALUE_NONE, 'Не включать maintenance mode во время apply.')
            ->addOption('no-migrations', null, InputOption::VALUE_NONE, 'Не запускать миграции после apply.')
            ->addOption('no-doctor', null, InputOption::VALUE_NONE, 'Не запускать doctor после apply.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Разрешить apply при неполной проверке package.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = new UpdateManager($this->rootPath, new ConfigRepository($this->rootPath));
        $options = [
            'package' => $input->getOption('package'),
            'sha256' => $input->getOption('sha256'),
            'version' => $input->getOption('version'),
            'mode' => $input->getOption('mode'),
            'dry_run' => (bool) $input->getOption('dry-run'),
            'no_backup' => (bool) $input->getOption('no-backup'),
            'no_maintenance' => (bool) $input->getOption('no-maintenance'),
            'no_migrations' => (bool) $input->getOption('no-migrations'),
            'no_doctor' => (bool) $input->getOption('no-doctor'),
            'force' => (bool) $input->getOption('force'),
        ];
        $result = match (true) {
            (bool) $input->getOption('apply') => $manager->apply($options),
            (bool) $input->getOption('prepare') => $manager->prepare($options),
            default => $manager->plan($options),
        };

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        if (($result['dry_run'] ?? false) === true) {
            $io->success('Update dry-run готов. Файлы не изменены.');
        } elseif (($result['applied_files'] ?? null) !== null) {
            $io->success('Update применён. Файлов обновлено: ' . count((array) $result['applied_files']));
            if (is_array($result['backup'] ?? null) && isset($result['backup']['target'])) {
                $io->text('Backup: ' . (string) $result['backup']['target']);
            }
        } else {
            $io->title('CajeerEngine update plan');
            $io->definitionList(
                ['Current' => (string) ($result['current_version'] ?? '')],
                ['Target' => (string) ($result['target_version'] ?? 'нет доступной версии')],
                ['Package' => (string) ($result['package'] ?? 'укажите --package=...')],
                ['Backup before update' => ((bool) ($result['backup_before_update'] ?? true)) ? 'yes' : 'no']
            );
            if (isset($result['apply_command'])) { $io->text('Apply: ' . (string) $result['apply_command']); }
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
