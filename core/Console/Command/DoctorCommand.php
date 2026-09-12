<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Server\ServerDoctorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'doctor', description: 'Полная диагностика сервера: PHP, права, БД, миграции, assets, security, Nginx, systemd.')]
final class DoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.')
            ->addOption('no-report', null, InputOption::VALUE_NONE, 'Не записывать storage/app/reports/doctor-latest.json.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ServerDoctorService($this->rootPath))->run(!(bool) $input->getOption('no-report'));
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $summary = $result['summary'] ?? [];
        $io->title('CajeerEngine Doctor');
        $io->writeln(sprintf('OK: %d, WARN: %d, FAIL: %d', (int) ($summary['ok'] ?? 0), (int) ($summary['warn'] ?? 0), (int) ($summary['fail'] ?? 0)));

        $rows = [];
        foreach (($result['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $rows[] = [
                (string) ($check['status'] ?? 'WARN'),
                (string) ($check['group'] ?? ''),
                (string) ($check['label'] ?? $check['name'] ?? ''),
                (string) ($check['fix_command'] ?? $check['fix'] ?? ''),
            ];
        }
        $io->table(['Status', 'Group', 'Check', 'Fix command'], $rows);
        $io->writeln('Report: storage/app/reports/doctor-latest.json');
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
