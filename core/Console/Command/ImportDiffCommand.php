<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:diff', description: 'Показать dry-run diff JSON-экспорта CajeerEngine без записи данных.')]
final class ImportDiffCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Путь или имя файла из storage/app/exports|imports.');
        $this->addOption('settings', null, InputOption::VALUE_REQUIRED, 'merge или replace для settings.', 'merge');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ImportExportService($this->rootPath))->diff((string) $input->getArgument('file'), [
            'settings' => (string) $input->getOption('settings'),
        ]);
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $summary = (array) ($result['summary'] ?? []);
        $output->writeln('[OK] Import diff: ' . $result['file']);
        $output->writeln('[INFO] create=' . (int) ($summary['create'] ?? 0) . ' update=' . (int) ($summary['update'] ?? 0) . ' skip=' . (int) ($summary['skip'] ?? 0) . ' delete=' . (int) ($summary['delete'] ?? 0));
        return self::SUCCESS;
    }
}
