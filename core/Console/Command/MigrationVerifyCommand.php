<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Rc\MigrationVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'migrate:verify', description: 'Проверить файлы миграций без подключения к БД.')] 
final class MigrationVerifyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = array_map(static fn ($check): array => $check->toArray(), (new MigrationVerifier($this->rootPath))->verify());
        $fail = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['checks' => $checks, 'fail' => $fail], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check) {
                $output->writeln(sprintf('[%s] %s — %s', strtoupper($check['status']), $check['name'], $check['message']));
            }
        }
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
