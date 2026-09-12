<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Server\ServerFixerService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class FixActionCommand extends BaseCommand
{
    public function __construct(string $rootPath, private readonly string $commandName, private readonly string $action, private readonly string $description)
    {
        parent::__construct($rootPath);
    }

    protected function configure(): void
    {
        $this
            ->setName($this->commandName)
            ->setDescription($this->description)
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Применить изменения. Без --yes работает dry-run.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = !(bool) $input->getOption('yes');
        $fixer = new ServerFixerService($this->rootPath);
        $result = match ($this->action) {
            'permissions' => $fixer->fixPermissions($dryRun),
            'env' => $fixer->fixEnv($dryRun),
            'security' => $fixer->fixSecurity($dryRun),
            'admin-assets' => $fixer->fixAdminAssets($dryRun),
            'storage' => $fixer->fixStorage($dryRun),
            default => throw new \RuntimeException('Unknown fix action: ' . $this->action),
        };
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
        }
        $io = new SymfonyStyle($input, $output);
        $io->success(($dryRun ? 'Dry-run: ' : '') . $this->commandName . ' завершена.');
        if ($dryRun) {
            $io->warning('Изменения не применены. Добавьте --yes для записи.');
        }
        return ($result['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}
