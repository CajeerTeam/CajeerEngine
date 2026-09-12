<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:run', description: 'Импортировать JSON-экспорт CajeerEngine v1.')]
final class ImportRunCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Путь или имя файла из storage/app/exports|imports.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Проверить импорт без записи.');
        $this->addOption('settings', null, InputOption::VALUE_REQUIRED, 'merge или replace для settings.', 'merge');
        $this->addOption('create-missing-users', null, InputOption::VALUE_NONE, 'Создавать отсутствующих пользователей disabled placeholders без паролей.');
        $this->addOption('delete-missing-content', null, InputOption::VALUE_NONE, 'Удалить записи, которых нет в импортируемом content snapshot.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ImportExportService($this->rootPath))->import((string) $input->getArgument('file'), [
            'dry_run' => (bool) $input->getOption('dry-run'),
            'settings' => (string) $input->getOption('settings'),
            'create_missing_users' => (bool) $input->getOption('create-missing-users'),
            'delete_missing_content' => (bool) $input->getOption('delete-missing-content'),
        ]);
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Import file: ' . $result['file']);
        $output->writeln('[OK] Imported: ' . implode(', ', (array) $result['imported']));
        $output->writeln('[INFO] Skipped: ' . implode(', ', (array) $result['skipped']));
        return self::SUCCESS;
    }
}
