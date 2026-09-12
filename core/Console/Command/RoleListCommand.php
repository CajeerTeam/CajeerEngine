<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'role:list', description: 'Показать роли и permissions.')]
final class RoleListCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roles = new RoleRepository(new DatabaseManager(new ConfigRepository($this->rootPath)));
        foreach ($roles->all() as $role) {
            $output->writeln($role['handle'] . '  ' . $role['name'] . '  permissions=' . implode(',', $role['permissions']));
        }
        return self::SUCCESS;
    }
}
