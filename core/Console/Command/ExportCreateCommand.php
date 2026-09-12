<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'export:create', description: 'Создать JSON-экспорт контента, media metadata, users, settings, themes и extensions.')]
final class ExportCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Секция экспорта: content, media, users, settings, themes, extensions.');
        $this->addOption('filename', null, InputOption::VALUE_REQUIRED, 'Имя файла экспорта.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = new ImportExportService($this->rootPath);
        $result = $service->createExport((array) $input->getOption('section'), $input->getOption('filename') ? (string) $input->getOption('filename') : null);
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Export: ' . $result['file']);
        $output->writeln('[OK] Sections: ' . implode(', ', (array) ($result['manifest']['sections'] ?? [])));
        return self::SUCCESS;
    }
}
