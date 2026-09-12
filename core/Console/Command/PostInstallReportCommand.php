<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Server\ServerDoctorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'post-install:report', description: 'Сформировать post-install report с итогами установки и следующими шагами.')]
final class PostInstallReportCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ServerDoctorService($this->rootPath))->postInstallReport();
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }
        $io = new SymfonyStyle($input, $output);
        $io->title('CajeerEngine Post-install Report');
        $io->definitionList(
            ['Version' => (string) ($result['version'] ?? '')],
            ['Installed' => !empty($result['installed']) ? 'yes' : 'no'],
            ['Report' => 'storage/app/reports/post-install-latest.json']
        );
        if (($result['next_steps'] ?? []) !== []) {
            $io->section('Next steps');
            foreach ($result['next_steps'] as $step) {
                $io->writeln('- ' . $step);
            }
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
