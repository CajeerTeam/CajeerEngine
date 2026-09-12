<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'security:harden', description: 'Применить безопасные значения в .env для существующей установки.')]
final class SecurityHardenCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
        $this->addOption('no-create', null, InputOption::VALUE_NONE, 'Не создавать .env из .env.example, если он отсутствует.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new SecurityHardener($this->rootPath, new ConfigRepository($this->rootPath)))->hardenEnv(!(bool) $input->getOption('no-create'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        $output->writeln('[OK] .env hardened: ' . $result['env_path']);
        $output->writeln('[OK] Changed: ' . $result['changed_count']);
        foreach ($result['changed'] as $key => $change) {
            $output->writeln(sprintf('  - %s: %s -> %s', $key, $change['old'] ?? 'NULL', $change['new']));
        }
        return self::SUCCESS;
    }
}
