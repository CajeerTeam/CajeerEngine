<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Observability\SystemReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:report', description: 'Показать или сохранить системный отчёт CajeerEngine.')]
final class SystemReportCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'json или text', 'json');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Путь для сохранения отчёта JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = (new SystemReport($this->rootPath))->toArray();
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $target = $input->getOption('output');
        if (is_string($target) && $target !== '') {
            $path = str_starts_with($target, '/') ? $target : $this->rootPath . '/' . $target;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $json . PHP_EOL, LOCK_EX);
            $output->writeln('[OK] System report saved: ' . $path);
            return self::SUCCESS;
        }
        if ((string) $input->getOption('format') === 'text') {
            $output->writeln('CajeerEngine ' . $report['engine']['version']);
            $output->writeln('PHP: ' . $report['runtime']['php']);
            $output->writeln('Environment: ' . $report['runtime']['environment']);
            $output->writeln('Storage writable: ' . ($report['paths']['storage_writable'] ? 'yes' : 'no'));
            $output->writeln('Content entries: ' . $report['product']['content_entries']);
            $output->writeln('Exports: ' . $report['product']['exports']);
            $output->writeln('Update source: ' . $report['product']['updates_metadata_source']);
            $output->writeln('Release builder: ' . ($report['product']['release_builder'] ? 'yes' : 'no'));
            return self::SUCCESS;
        }
        $output->writeln($json);
        return self::SUCCESS;
    }
}
