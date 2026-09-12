<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Installer\InstallerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'install', description: 'Установить CajeerEngine: .env, БД, миграции, первый администратор, install lock.')]
final class InstallCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('interactive', null, InputOption::VALUE_NONE, 'Запустить интерактивный мастер установки.')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Preset: sqlite, postgres, mysql, production, aapanel.')
            ->addOption('sqlite', null, InputOption::VALUE_NONE, 'Быстрый SQLite install: storage/database/cajeer.sqlite.')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'DB driver: sqlite, pgsql, mysql, mariadb.')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host.')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port.')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB database/name или путь к SQLite-файлу.')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'DB user.')
            ->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'DB password.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Домен проекта, например site.ru.')
            ->addOption('admin-name', null, InputOption::VALUE_REQUIRED, 'Имя первого администратора.')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Email первого администратора.')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Пароль первого администратора.')
            ->addOption('production', null, InputOption::VALUE_NONE, 'Включить production hardening в .env.')
            ->addOption('no-env', null, InputOption::VALUE_NONE, 'Не создавать и не менять .env.')
            ->addOption('no-migrate', null, InputOption::VALUE_NONE, 'Не запускать миграции.')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Не создавать первого администратора.')
            ->addOption('no-lock', null, InputOption::VALUE_NONE, 'Не создавать storage/app/installed.lock.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Переустановить даже при наличии installed.lock.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $options = $this->collectOptions($input, $io);
        $result = (new InstallerService($this->rootPath))->install($options);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $io->success('CajeerEngine ' . ($result['database_driver'] ?? '') . ' установлен.');
        $io->definitionList(
            ['Preset' => (string) ($result['preset'] ?? 'custom')],
            ['Domain' => (string) ($result['domain'] ?? '')],
            ['DB' => (string) ($result['database_driver'] ?? '')],
            ['.env' => !empty($result['env_created']) ? 'created' : 'updated/exists'],
            ['Migrations applied' => (string) (($result['migrations']['applied'] ?? 0))],
            ['Admin' => (string) (($result['seed']['admin_email'] ?? 'not created'))],
            ['Lock' => (string) ($result['lock_file'] ?? '')]
        );

        $finalOk = (bool) ($result['final']['ok'] ?? false);
        if (!$finalOk) {
            $io->warning('Финальная проверка завершилась с предупреждениями. Запустите: php bin/cajeer install:check или php bin/cajeer security:doctor');
        }

        return $finalOk ? self::SUCCESS : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function collectOptions(InputInterface $input, SymfonyStyle $io): array
    {
        $options = [
            'source' => 'cli',
            'preset' => $input->getOption('preset'),
            'sqlite' => (bool) $input->getOption('sqlite'),
            'db' => $input->getOption('db'),
            'db_host' => $input->getOption('db-host'),
            'db_port' => $input->getOption('db-port'),
            'db_name' => $input->getOption('db-name'),
            'db_user' => $input->getOption('db-user'),
            'db_password' => $input->getOption('db-password'),
            'domain' => $input->getOption('domain'),
            'admin_name' => $input->getOption('admin-name'),
            'admin_email' => $input->getOption('admin-email'),
            'admin_password' => $input->getOption('admin-password'),
            'production' => (bool) $input->getOption('production'),
            'create_env' => !(bool) $input->getOption('no-env'),
            'run_migrations' => !(bool) $input->getOption('no-migrate'),
            'create_admin' => !(bool) $input->getOption('no-admin'),
            'write_lock' => !(bool) $input->getOption('no-lock'),
            'force' => (bool) $input->getOption('force'),
        ];

        if (!$input->getOption('interactive')) {
            return array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $io->title('CajeerEngine Install Wizard');
        $preset = (string) ($options['preset'] ?: $io->choice('Режим установки', ['sqlite', 'postgres', 'mysql', 'production', 'aapanel', 'custom'], 'sqlite'));
        $options['preset'] = $preset;
        $options['domain'] = (string) ($options['domain'] ?: $io->ask('Домен', '127.0.0.1:8080'));
        $driverDefault = match ($preset) { 'sqlite' => 'sqlite', 'mysql' => 'mysql', default => 'pgsql' };
        $options['db'] = (string) ($options['db'] ?: $io->choice('Тип БД', ['sqlite', 'pgsql', 'mysql', 'mariadb'], $driverDefault));
        if ($options['db'] !== 'sqlite') {
            $options['db_host'] = (string) ($options['db_host'] ?: $io->ask('DB host', '127.0.0.1'));
            $options['db_port'] = (string) ($options['db_port'] ?: $io->ask('DB port', $options['db'] === 'mysql' ? '3306' : '5432'));
            $options['db_name'] = (string) ($options['db_name'] ?: $io->ask('DB database', 'cajeerengine'));
            $options['db_user'] = (string) ($options['db_user'] ?: $io->ask('DB user', 'cajeerengine'));
            $options['db_password'] = (string) ($options['db_password'] ?: $io->askHidden('DB password'));
        } else {
            $options['db_name'] = (string) ($options['db_name'] ?: $io->ask('SQLite path', 'storage/database/cajeer.sqlite'));
        }
        $options['admin_name'] = (string) ($options['admin_name'] ?: $io->ask('Имя администратора', 'Administrator'));
        $options['admin_email'] = (string) ($options['admin_email'] ?: $io->ask('Email администратора'));
        $options['admin_password'] = (string) ($options['admin_password'] ?: $io->askHidden('Пароль администратора'));
        $options['production'] = (bool) ($options['production'] || $io->confirm('Включить production hardening?', in_array($preset, ['production', 'aapanel', 'postgres'], true)));
        $options['create_env'] = $io->confirm('Создать/обновить .env?', true);
        $options['run_migrations'] = $io->confirm('Запустить миграции?', true);
        $options['create_admin'] = $io->confirm('Создать первого администратора?', true);
        $options['write_lock'] = $io->confirm('Создать install lock?', true);

        return array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
