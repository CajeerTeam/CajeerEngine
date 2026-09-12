<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:create', description: 'Создать пользователя CajeerEngine.')]
final class UserCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email пользователя.');
        $this->addArgument('name', InputArgument::REQUIRED, 'Имя пользователя.');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Пароль пользователя.');
        $this->addOption('role', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Роль пользователя.', ['viewer']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $roles = new RoleRepository($database);
        $users = new UserRepository($database, $roles);
        $hasher = new PasswordHasher($config);
        $password = (string) ($input->getOption('password') ?: getenv('USER_PASSWORD') ?: '');
        if ($password === '') {
            $output->writeln('<error>Передайте --password или USER_PASSWORD.</error>');
            return self::FAILURE;
        }
        $id = $users->create((string) $input->getArgument('email'), (string) $input->getArgument('name'), $hasher->hash($password), array_values(array_map('strval', (array) $input->getOption('role'))));
        $output->writeln('[OK] Пользователь создан: ' . $id);
        return self::SUCCESS;
    }
}
