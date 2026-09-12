<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\AuditLogRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\SettingsRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\PasswordHasher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:seed', description: 'Создать базовые роли, настройки и опционально первого администратора.')]
final class DatabaseSeedCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Email первого администратора.');
        $this->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Пароль первого администратора, минимум 12 символов.');
        $this->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Имя первого администратора.', 'Administrator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $roles = new RoleRepository($database);
        $settings = new SettingsRepository($database);
        $users = new UserRepository($database, $roles, new PasswordHasher($config));
        $audit = new AuditLogRepository($database);

        $roleCount = $roles->seedDefaults();
        $settings->set('app.name', $config->string('app.name', 'CajeerEngine'), 'string');
        $settings->set('app.locale', $config->string('app.locale', 'ru'), 'string');
        $settings->set('engine.version', $config->string('app.version', '0.3.0'), 'string');
        $settings->set('engine.database_driver', $database->driver(), 'string');

        $output->writeln('[OK] Базовые роли обновлены: ' . $roleCount);
        $output->writeln('[OK] Базовые настройки записаны.');

        $email = (string) ($input->getOption('admin-email') ?: getenv('ADMIN_EMAIL') ?: '');
        $password = (string) ($input->getOption('admin-password') ?: getenv('ADMIN_PASSWORD') ?: '');
        $name = (string) ($input->getOption('admin-name') ?: getenv('ADMIN_NAME') ?: 'Administrator');

        if ($email !== '' || $password !== '') {
            $userId = $users->createAdmin($email, $name, $password);
            $output->writeln('[OK] Администратор готов: ' . $email . ' (' . $userId . ')');
        } else {
            $output->writeln('[INFO] Администратор не создан. Передайте --admin-email и --admin-password или задайте ADMIN_EMAIL/ADMIN_PASSWORD.');
        }

        $audit->record('database.seeded', null, ['roles' => $roleCount, 'admin_email' => $email !== '' ? $email : null]);
        return self::SUCCESS;
    }
}
