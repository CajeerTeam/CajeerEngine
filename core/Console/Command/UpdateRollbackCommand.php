<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update:rollback', description: 'Восстановить файлы из rollback snapshot, созданного перед update apply.')]
final class UpdateRollbackCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('backup', null, InputOption::VALUE_REQUIRED, 'Путь к storage/app/updates/rollback/*.zip.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new UpdateManager($this->rootPath, new ConfigRepository($this->rootPath)))->rollback($input->getOption('backup') ? (string) $input->getOption('backup') : null);
        $output->writeln((bool) $input->getOption('json') ? (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '[OK] Rollback выполнен. Файлов восстановлено: ' . count((array) ($result['restored_files'] ?? [])));
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
