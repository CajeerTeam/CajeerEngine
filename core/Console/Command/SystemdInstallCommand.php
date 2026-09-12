<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Server\SystemdServiceGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SystemdInstallCommand extends BaseCommand
{
    public function __construct(string $rootPath, private readonly string $commandName, private readonly string $kind, private readonly string $description)
    {
        parent::__construct($rootPath);
    }

    protected function configure(): void
    {
        $this
            ->setName($this->commandName)
            ->setDescription($this->description)
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP binary path.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'System user for unit.')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'System group for unit.')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Worker count metadata.', '1')
            ->addOption('target-dir', null, InputOption::VALUE_REQUIRED, 'Куда записать unit-файлы. По умолчанию storage/app/systemd.')
            ->addOption('system', null, InputOption::VALUE_NONE, 'Писать в /etc/systemd/system. Требует прав root.')
            ->addOption('print', null, InputOption::VALUE_NONE, 'Только вывести unit-файлы.')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Записать unit-файлы.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'php' => $input->getOption('php'),
            'user' => $input->getOption('user'),
            'group' => $input->getOption('group'),
            'workers' => $input->getOption('workers'),
            'target_dir' => $input->getOption('target-dir'),
            'system' => (bool) $input->getOption('system'),
            'dry_run' => !(bool) $input->getOption('write'),
        ];
        $options = array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
        $generator = new SystemdServiceGenerator($this->rootPath);
        if ((bool) $input->getOption('print') || !(bool) $input->getOption('write')) {
            $preview = $generator->preview($options);
            $units = $this->kind === 'scheduler'
                ? array_intersect_key($preview['units'], array_flip(['cajeerengine-scheduler.service', 'cajeerengine-scheduler.timer']))
                : array_intersect_key($preview['units'], array_flip(['cajeerengine-worker.service']));
            $result = ['ok' => true, 'dry_run' => true, 'units' => $units, 'unit_names' => $preview['unit_names'] ?? []];
        } else {
            $result = $this->kind === 'scheduler' ? $generator->writeScheduler($options) : $generator->writeWorker($options);
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        foreach (($result['units'] ?? []) as $name => $content) {
            $io->section((string) $name);
            $output->writeln((string) $content);
        }
        if (!empty($result['written'])) {
            $io->success('Unit-файлы записаны. Выполните systemctl daemon-reload и enable --now нужных unit.');
        } else {
            $io->warning('Unit-файлы не записаны. Добавьте --write или --system --write.');
        }
        return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}
