<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api-token:create', description: 'Создать API token.')]
final class ApiTokenCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'ID пользователя.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Название token.', 'cli-token');
        $this->addOption('scope', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Scope token.', ['content:read']);
        $this->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Дата истечения, например +30 days.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokens = new ApiTokenRepository(new DatabaseManager(new ConfigRepository($this->rootPath)));
        $expires = $input->getOption('expires') ? new \DateTimeImmutable((string) $input->getOption('expires')) : null;
        $token = $tokens->create($input->getOption('user-id') ? (string) $input->getOption('user-id') : null, (string) $input->getOption('name'), array_values(array_map('strval', (array) $input->getOption('scope'))), $expires);
        $output->writeln('[OK] Token ID: ' . $token['id']);
        $output->writeln('Plain token: ' . $token['plain_token']);
        return self::SUCCESS;
    }
}
