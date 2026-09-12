<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:list', description: 'Показать пользователей CajeerEngine.')]
final class UserListCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = new DatabaseManager(new ConfigRepository($this->rootPath));
        $users = new UserRepository($database, new RoleRepository($database));
        foreach ($users->all() as $user) {
            $output->writeln($user['id'] . '  ' . $user['email'] . '  ' . $user['status'] . '  roles=' . implode(',', $user['roles'] ?? []));
        }
        return self::SUCCESS;
    }
}
