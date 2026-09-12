<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'export:list', description: 'Показать созданные export-файлы.')]
final class ExportListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = (new ImportExportService($this->rootPath))->exports();
        if ($input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => ['total' => count($items)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln($item['file'] . ' ' . $item['size'] . ' bytes ' . implode(',', (array) $item['sections']));
        }
        if ($items === []) {
            $output->writeln('[INFO] Экспорты не найдены.');
        }
        return self::SUCCESS;
    }
}
