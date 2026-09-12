<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\ApiTokenRepository;
use CajeerEngine\Database\Repository\RoleRepository;
use CajeerEngine\Database\Repository\UserRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'security:doctor', description: 'Проверить состояние Security Core и production hardening.')] 
final class SecurityDoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $roles = new RoleRepository($database);
        $users = new UserRepository($database, $roles);
        $tokens = new ApiTokenRepository($database);
        $health = $database->health();
        $hardening = (new SecurityHardener($this->rootPath, $config))->diagnose();

        $result = [
            'ok' => $database->securityCoreReady() && (bool) $hardening['ok'],
            'driver' => $database->driver(),
            'security_core_ready' => $database->securityCoreReady(),
            'auth_required' => $config->bool('security.api_auth_required', true),
            'protect_content_writes' => $config->bool('security.protect_content_writes', true),
            'protect_system_routes' => $config->bool('security.protect_system_routes', true),
            'users' => $this->safe(fn () => $users->count()),
            'roles' => $this->safe(fn () => count($roles->all())),
            'api_tokens' => $this->safe(fn () => $tokens->count()),
            'hardening' => $hardening,
            'missing_security_columns' => $health['security_core']['missing_columns'] ?? [],
        ];

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $output->writeln('Security Core: ' . ($result['security_core_ready'] ? 'OK' : 'NOT READY'));
        $output->writeln('DB driver: ' . $result['driver']);
        $output->writeln('Auth required: ' . ($result['auth_required'] ? 'yes' : 'no'));
        $output->writeln('Protect content writes: ' . ($result['protect_content_writes'] ? 'yes' : 'no'));
        $output->writeln('Protect system routes: ' . ($result['protect_system_routes'] ? 'yes' : 'no'));
        $output->writeln('Users: ' . $result['users']);
        $output->writeln('Roles: ' . $result['roles']);
        $output->writeln('API tokens: ' . $result['api_tokens']);
        $output->writeln('Production hardening: ' . ($hardening['ok'] ? 'OK' : 'FAILED'));
        if (($hardening['failures'] ?? []) !== []) {
            $output->writeln('Hardening failures: ' . implode(', ', (array) $hardening['failures']));
        }

        $missing = $result['missing_security_columns'];
        if (is_array($missing) && $missing !== []) {
            $output->writeln('Missing security columns: ' . implode(', ', $missing));
        }
        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function safe(callable $callback): int
    {
        try { return (int) $callback(); } catch (\Throwable) { return 0; }
    }
}
