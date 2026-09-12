<?php

declare(strict_types=1);

namespace CajeerEngine\Server;

use CajeerEngine\Admin\AdminAssetPublisher;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SecurityHardener;
use CajeerEngine\Support\EnvironmentFile;

final readonly class ServerFixerService
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string>|null $actions @return array<string,mixed> */
    public function fix(?array $actions = null, bool $dryRun = false): array
    {
        $actions ??= ['storage', 'permissions', 'env', 'security', 'admin-assets'];
        $results = [];
        foreach ($actions as $action) {
            $results[$action] = $this->runAction($action, $dryRun);
        }
        $ok = true;
        foreach ($results as $result) {
            if (($result['ok'] ?? false) !== true) {
                $ok = false;
                break;
            }
        }
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'actions' => $results,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function fixStorage(bool $dryRun = false): array
    {
        $created = [];
        $gitkeep = [];
        foreach ($this->runtimeDirectories() as $dir) {
            $path = $this->rootPath . '/' . $dir;
            if (!is_dir($path)) {
                if (!$dryRun && !mkdir($path, 0775, true) && !is_dir($path)) {
                    throw new \RuntimeException('Не удалось создать директорию: ' . $dir);
                }
                $created[] = $dir;
            }
            $keepPath = $path . '/.gitkeep';
            if (!is_file($keepPath)) {
                if (!$dryRun) {
                    if (!is_dir($path)) {
                        mkdir($path, 0775, true);
                    }
                    file_put_contents($keepPath, '', LOCK_EX);
                }
                $gitkeep[] = $dir . '/.gitkeep';
            }
        }
        return ['ok' => true, 'created' => $created, 'gitkeep' => $gitkeep, 'dry_run' => $dryRun];
    }

    /** @return array<string,mixed> */
    public function fixPermissions(bool $dryRun = false, int $directoryMode = 0775, int $fileMode = 0664): array
    {
        $this->fixStorage($dryRun);
        $changed = [];
        foreach ($this->runtimeDirectories() as $dir) {
            $path = $this->rootPath . '/' . $dir;
            if (is_dir($path)) {
                if (!$dryRun) {
                    @chmod($path, $directoryMode);
                }
                $changed[] = ['path' => $dir, 'mode' => decoct($directoryMode), 'type' => 'dir'];
            }
        }
        foreach (['.env', 'storage/app/installed.lock'] as $file) {
            $path = $this->rootPath . '/' . $file;
            if (is_file($path)) {
                if (!$dryRun) {
                    @chmod($path, $fileMode);
                }
                $changed[] = ['path' => $file, 'mode' => decoct($fileMode), 'type' => 'file'];
            }
        }
        return ['ok' => true, 'changed' => $changed, 'dry_run' => $dryRun];
    }

    /** @return array<string,mixed> */
    public function fixEnv(bool $dryRun = false): array
    {
        $path = $this->rootPath . '/.env';
        $example = $this->rootPath . '/.env.example';
        $created = false;
        if (!is_file($path)) {
            if (!is_file($example)) {
                throw new \RuntimeException('.env.example не найден. Нельзя безопасно создать .env.');
            }
            if (!$dryRun && !copy($example, $path)) {
                throw new \RuntimeException('Не удалось создать .env из .env.example.');
            }
            $created = true;
        }

        $changes = [];
        if (!$dryRun) {
            $env = new EnvironmentFile($path);
            $changes = $env->setMany($this->safeEnvDefaults());
        } else {
            $changes = ['DRY_RUN' => ['old' => null, 'new' => 'safe env defaults would be written']];
        }

        return ['ok' => true, 'created' => $created, 'changed' => $changes, 'dry_run' => $dryRun];
    }

    /** @return array<string,mixed> */
    public function fixSecurity(bool $dryRun = false): array
    {
        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'changed_count' => 0, 'message' => 'security hardening would be written to .env'];
        }
        $config = new ConfigRepository($this->rootPath);
        return (new SecurityHardener($this->rootPath, $config))->hardenEnv(true);
    }

    /** @return array<string,mixed> */
    public function fixAdminAssets(bool $dryRun = false): array
    {
        $base = $this->rootPath . '/public/admin';
        $assets = $base . '/assets';
        $created = [];
        if (!$dryRun && !is_dir($assets)) {
            mkdir($assets, 0775, true);
        }

        $files = [
            'index.html' => $this->adminIndexHtml(),
            'assets/admin-app.css' => $this->adminCss(),
            'assets/admin-app.js' => $this->adminJs(),
            'assets/admin-preview.css' => "@import url('/admin/assets/admin-app.css');\n",
            'assets/admin-preview.js' => "import '/admin/assets/admin-app.js';\n",
        ];
        foreach ($files as $relative => $content) {
            $path = $base . '/' . $relative;
            $needsWrite = !is_file($path) || filesize($path) === 0;
            if ($needsWrite) {
                if (!$dryRun) {
                    if (!is_dir(dirname($path))) {
                        mkdir(dirname($path), 0775, true);
                    }
                    file_put_contents($path, $content, LOCK_EX);
                }
                $created[] = 'public/admin/' . $relative;
            }
        }

        $manifest = $dryRun ? ['ready' => true, 'dry_run' => true] : (new AdminAssetPublisher($this->rootPath))->manifest(true);
        return ['ok' => true, 'created' => $created, 'manifest' => $manifest, 'dry_run' => $dryRun];
    }

    /** @return array<string,mixed> */
    private function runAction(string $action, bool $dryRun): array
    {
        return match ($action) {
            'storage' => $this->fixStorage($dryRun),
            'permissions' => $this->fixPermissions($dryRun),
            'env' => $this->fixEnv($dryRun),
            'security' => $this->fixSecurity($dryRun),
            'admin-assets', 'admin_assets' => $this->fixAdminAssets($dryRun),
            default => throw new \InvalidArgumentException('Неизвестное fix-действие: ' . $action),
        };
    }

    /** @return list<string> */
    private function runtimeDirectories(): array
    {
        return ['storage', 'storage/app', 'storage/cache', 'storage/database', 'storage/logs', 'storage/tmp', 'storage/app/reports', 'storage/app/updates', 'storage/app/support', 'storage/app/backups', 'public/uploads', 'bootstrap/cache'];
    }

    /** @return array<string,string> */
    private function safeEnvDefaults(): array
    {
        $existing = $this->readEnv();
        return [
            'APP_ENV' => $existing['APP_ENV'] ?? 'production',
            'APP_DEBUG' => $existing['APP_DEBUG'] ?? 'false',
            'APP_KEY' => $this->secret($existing['APP_KEY'] ?? null, 'base64:' . base64_encode(random_bytes(32))),
            'APP_VERSION' => trim((string) @file_get_contents($this->rootPath . '/VERSION')) ?: '1.1.1',
            'JWT_SECRET' => $this->secret($existing['JWT_SECRET'] ?? null, bin2hex(random_bytes(48))),
            'API_TOKEN_SECRET' => $this->secret($existing['API_TOKEN_SECRET'] ?? null, bin2hex(random_bytes(48))),
            'WEBHOOK_SIGNING_SECRET' => $this->secret($existing['WEBHOOK_SIGNING_SECRET'] ?? null, bin2hex(random_bytes(48))),
            'SECURITY_REQUIRE_AUTH' => $existing['SECURITY_REQUIRE_AUTH'] ?? 'true',
            'SECURITY_PROTECT_CONTENT_WRITES' => $existing['SECURITY_PROTECT_CONTENT_WRITES'] ?? 'true',
            'SECURITY_PROTECT_SYSTEM_ROUTES' => $existing['SECURITY_PROTECT_SYSTEM_ROUTES'] ?? 'true',
            'MEDIA_ALLOW_SVG' => $existing['MEDIA_ALLOW_SVG'] ?? 'false',
            'PASSWORD_MIN_LENGTH' => $existing['PASSWORD_MIN_LENGTH'] ?? '12',
        ];
    }

    /** @return array<string,string> */
    private function readEnv(): array
    {
        $path = $this->rootPath . '/.env';
        if (!is_file($path)) {
            return [];
        }
        $result = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $result[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
        return $result;
    }

    private function secret(?string $current, string $generated): string
    {
        $current = trim((string) $current);
        if ($current !== '' && !in_array($current, ['CHANGE_ME', 'base64:CHANGE_ME', 'changeme'], true)) {
            return $current;
        }
        return $generated;
    }

    private function adminIndexHtml(): string
    {
        return $this->adminStaticFile('index.html', <<<'HTML'
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>CajeerEngine Admin 1.1.1</title><link rel="stylesheet" href="/admin/assets/admin-app.css"></head><body><noscript>Для CajeerEngine Admin UI нужен JavaScript.</noscript><div id="app"></div><script type="module" src="/admin/assets/admin-app.js"></script></body></html>
HTML);
    }

    private function adminCss(): string
    {
        return $this->adminStaticFile('assets/admin-app.css', 'body{margin:0;background:#f4f7fb;color:#172033;font-family:Inter,system-ui,sans-serif}.empty{padding:24px}\n');
    }

    private function adminJs(): string
    {
        return $this->adminStaticFile('assets/admin-app.js', "document.getElementById('app').innerHTML='<main class=empty><h1>CajeerEngine Admin UI 1.1.1</h1><p>Admin assets were repaired. Run admin/scripts/publish-admin-assets.mjs for full production assets.</p></main>'\n");
    }

    private function adminStaticFile(string $relative, string $fallback): string
    {
        $path = $this->rootPath . '/admin/static/' . $relative;
        if (is_file($path) && filesize($path) > 0) {
            return (string) file_get_contents($path);
        }
        return $fallback;
    }
}
