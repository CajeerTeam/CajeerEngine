<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api-token:revoke', description: 'Отозвать API token.')]
final class ApiTokenRevokeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'ID token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokens = new ApiTokenRepository(new DatabaseManager(new ConfigRepository($this->rootPath)));
        $ok = $tokens->revoke((string) $input->getArgument('id'));
        $output->writeln($ok ? '[OK] Token отозван.' : '[WARN] Token не найден или уже отозван.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
