<?php

declare(strict_types=1);

namespace CajeerEngine\Server;

final readonly class SystemdServiceGenerator
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function preview(array $options = []): array
    {
        $php = (string) ($options['php'] ?? $this->detectPhpBinary());
        $user = (string) ($options['user'] ?? $this->detectUser());
        $group = (string) ($options['group'] ?? $user);
        $workerCount = max(1, (int) ($options['workers'] ?? 1));
        $schedulerService = $this->schedulerService($php, $user, $group);
        $schedulerTimer = $this->schedulerTimer();
        $workerService = $this->workerService($php, $user, $group, $workerCount);
        return [
            'ok' => true,
            'php' => $php,
            'user' => $user,
            'group' => $group,
            'root' => $this->rootPath,
            'unit_names' => [
                'scheduler_service' => 'cajeerengine-scheduler.service',
                'scheduler_timer' => 'cajeerengine-scheduler.timer',
                'worker_service' => 'cajeerengine-worker.service',
            ],
            'units' => [
                'cajeerengine-scheduler.service' => $schedulerService,
                'cajeerengine-scheduler.timer' => $schedulerTimer,
                'cajeerengine-worker.service' => $workerService,
            ],
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function writeScheduler(array $options = []): array
    {
        $preview = $this->preview($options);
        return $this->writeUnits($preview, ['cajeerengine-scheduler.service', 'cajeerengine-scheduler.timer'], $options);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function writeWorker(array $options = []): array
    {
        $preview = $this->preview($options);
        return $this->writeUnits($preview, ['cajeerengine-worker.service'], $options);
    }

    /** @param array<string,mixed> $preview @param list<string> $names @param array<string,mixed> $options @return array<string,mixed> */
    private function writeUnits(array $preview, array $names, array $options): array
    {
        $targetDir = rtrim((string) ($options['target_dir'] ?? ($options['system'] ?? false ? '/etc/systemd/system' : $this->rootPath . '/storage/app/systemd')), '/');
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $written = [];
        foreach ($names as $name) {
            $content = (string) ($preview['units'][$name] ?? '');
            if ($content === '') {
                continue;
            }
            $path = $targetDir . '/' . $name;
            if (!$dryRun) {
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                    throw new \RuntimeException('Не удалось создать директорию: ' . dirname($path));
                }
                file_put_contents($path, $content, LOCK_EX);
            }
            $written[$name] = $path;
        }
        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'target_dir' => $targetDir,
            'written' => $dryRun ? [] : $written,
            'would_write' => $dryRun ? $written : [],
            'units' => array_intersect_key($preview['units'], array_flip($names)),
            'next_commands' => [
                'sudo systemctl daemon-reload',
                in_array('cajeerengine-scheduler.timer', $names, true) ? 'sudo systemctl enable --now cajeerengine-scheduler.timer' : null,
                in_array('cajeerengine-worker.service', $names, true) ? 'sudo systemctl enable --now cajeerengine-worker.service' : null,
            ],
        ];
    }

    private function schedulerService(string $php, string $user, string $group): string
    {
        $root = $this->rootPath;
        return <<<UNIT
[Unit]
Description=CajeerEngine Scheduler Runner
After=network.target

[Service]
Type=oneshot
User={$user}
Group={$group}
WorkingDirectory={$root}
ExecStart={$php} {$root}/bin/cajeer scheduler:run
Nice=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths={$root}/storage {$root}/bootstrap/cache {$root}/public/uploads

[Install]
WantedBy=multi-user.target
UNIT;
    }

    private function schedulerTimer(): string
    {
        return <<<'UNIT'
[Unit]
Description=Run CajeerEngine Scheduler every minute

[Timer]
OnBootSec=60
OnUnitActiveSec=60
Unit=cajeerengine-scheduler.service
AccuracySec=10

[Install]
WantedBy=timers.target
UNIT;
    }

    private function workerService(string $php, string $user, string $group, int $workers): string
    {
        $root = $this->rootPath;
        return <<<UNIT
[Unit]
Description=CajeerEngine Queue Worker
After=network.target

[Service]
Type=simple
User={$user}
Group={$group}
WorkingDirectory={$root}
ExecStart={$php} {$root}/bin/cajeer queue:work --sleep=2 --tries=3
Restart=always
RestartSec=5
StartLimitIntervalSec=300
StartLimitBurst=10
Environment=CAJEER_WORKERS={$workers}
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths={$root}/storage {$root}/bootstrap/cache {$root}/public/uploads

[Install]
WantedBy=multi-user.target
UNIT;
    }

    private function detectPhpBinary(): string
    {
        foreach (['/www/server/php/84/bin/php', PHP_BINARY, '/usr/bin/php8.4', '/usr/bin/php'] as $binary) {
            if (is_string($binary) && $binary !== '' && is_file($binary)) {
                return $binary;
            }
        }
        return 'php';
    }

    private function detectUser(): string
    {
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        if (is_string($user) && $user !== '') {
            return $user;
        }
        return is_dir('/www/wwwroot') ? 'www' : 'www-data';
    }
}
