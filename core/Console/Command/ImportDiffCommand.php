<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'import:diff', description: 'Показать dry-run diff ZIP/JSON-пакета CajeerEngine без записи данных.')]
final class ImportDiffCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Путь или имя файла из storage/app/exports|imports.');
        $this->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'fail, skip или update.', 'fail');
        $this->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Выбранная секция.');
        $this->addOption('settings', null, InputOption::VALUE_REQUIRED, 'Только merge; replace отклоняется.', 'merge');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ImportExportService($this->rootPath))->diff((string) $input->getArgument('file'), [
            'strategy' => (string)$input->getOption('strategy'),
            'sections' => array_merge([], ...array_map(static fn($v)=>explode(',',$v), (array)$input->getOption('section'))),
            'settings' => (string) $input->getOption('settings'),
        ]);
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok']??false)?self::SUCCESS:self::FAILURE;
        }
        $output->writeln('[INFO] Plan: ' . $result['plan']);
        $summary = (array) ($result['summary'] ?? []);
        $output->writeln('[OK] Import diff: ' . $result['file']);
        $output->writeln('[INFO] create=' . (int) ($summary['create'] ?? 0) . ' update=' . (int) ($summary['update'] ?? 0) . ' skip=' . (int) ($summary['skip'] ?? 0) . ' delete=' . (int) ($summary['delete'] ?? 0));
        return ($result['ok']??false)?self::SUCCESS:self::FAILURE;
    }
}
