<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:run', description: 'Импортировать ZIP/JSON-пакет CajeerEngine.')]
final class ImportRunCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Путь или имя файла из storage/app/exports|imports.');
        $this->addOption('plan', null, InputOption::VALUE_REQUIRED, 'SHA256 плана из import:diff.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Проверить импорт без записи.');
        $this->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'fail, skip или update.', 'fail');
        $this->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Выбранная секция.');
        $this->addOption('settings', null, InputOption::VALUE_REQUIRED, 'Только merge; replace отклоняется.', 'merge');
        $this->addOption('create-missing-users', null, InputOption::VALUE_NONE, 'Устаревший флаг, отклоняется для безопасности.');
        $this->addOption('delete-missing-content', null, InputOption::VALUE_NONE, 'Устаревший destructive-флаг, отклоняется.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ImportExportService($this->rootPath))->import((string) $input->getArgument('file'), [
            'plan' => $input->getOption('plan'),
            'dry_run' => (bool) $input->getOption('dry-run'),
            'strategy' => (string)$input->getOption('strategy'),
            'sections' => array_merge([], ...array_map(static fn($v)=>explode(',',$v), (array)$input->getOption('section'))),
            'settings' => (string) $input->getOption('settings'),
            'create_missing_users' => (bool) $input->getOption('create-missing-users'),
            'delete_missing_content' => (bool) $input->getOption('delete-missing-content'),
        ]);
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok']??false)?self::SUCCESS:self::FAILURE;
        }
        if (!$result['ok']) { $output->writeln('[FAIL] ' . implode('; ', $result['errors']??[])); return self::FAILURE; }
        $output->writeln('[OK] Import file: ' . $result['file']);
        $output->writeln('[OK] Imported: ' . implode(', ', (array) $result['imported']));
        $output->writeln('[INFO] Skipped: ' . implode(', ', (array) $result['skipped']));
        return ($result['ok']??false)?self::SUCCESS:self::FAILURE;
    }
}
