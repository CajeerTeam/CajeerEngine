<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install:check', description: 'Проверить готовность окружения к установке CajeerEngine.')] 
final class InstallCheckCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $database = new DatabaseManager($config);
        $selectedDriver = $database->driver();
        $driverExtension = match ($selectedDriver) {
            'sqlite' => 'pdo_sqlite',
            'mysql' => 'pdo_mysql',
            default => 'pdo_pgsql',
        };

        $checks = [
            'PHP >= 8.4' => version_compare(PHP_VERSION, '8.4.1', '>='),
            'JSON extension' => extension_loaded('json'),
            'MBString extension' => extension_loaded('mbstring'),
            'OpenSSL extension' => extension_loaded('openssl'),
            'PDO extension' => extension_loaded('pdo'),
            'Selected DB driver extension: ' . $driverExtension => extension_loaded($driverExtension),
            'Composer autoload mapping core/' => $this->composerAutoloadUsesCore(),
            'config/app.php loaded' => $config->has('app.name'),
            'config/database.php loaded' => $config->has('database.default'),
            'runtime version resolved' => $config->string('app.version') !== '',
            'storage writable' => $this->writable('storage'),
            'storage/app writable or creatable' => $this->writable('storage/app'),
            'storage/cache writable or creatable' => $this->writable('storage/cache'),
            'storage/logs writable or creatable' => $this->writable('storage/logs'),
            'storage/tmp writable or creatable' => $this->writable('storage/tmp'),
        ];

        foreach ($checks as $name => $ok) {
            $output->writeln(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $name));
        }

        $health = $database->health();
        if (($health['ok'] ?? false) === true) {
            $output->writeln('[OK] Database connection: ' . ($health['driver'] ?? $selectedDriver) . ' ' . ($health['server_version'] ?? ''));
            foreach (($health['missing_tables'] ?? []) as $table) {
                $output->writeln('[WARN] DB table missing before migrate: ' . $table);
            }
        } else {
            $output->writeln('[WARN] Database connection не готово: ' . ($health['error']['message'] ?? 'unknown'));
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function writable(string $relativePath): bool
    {
        $path = $this->rootPath . '/' . $relativePath;
        if (!is_dir($path)) {
            return is_writable(dirname($path));
        }

        return is_writable($path);
    }

    private function composerAutoloadUsesCore(): bool
    {
        $path = $this->rootPath . '/composer.json';
        if (!is_file($path)) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($path), true);
        return is_array($composer) && (($composer['autoload']['psr-4']['CajeerEngine\\'] ?? null) === 'core/');
    }
}
