<?php

declare(strict_types=1);

namespace CajeerEngine\Runtime;

final class ConfigRepository
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(private readonly string $rootPath)
    {
        $this->loadPhpConfigs();
        $this->items['runtime'] = [
            'root_path' => $this->rootPath,
            'booted_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->items['app']['version'] = $this->readVersion();
        $this->items['app']['engine_api_version'] = (string) ($this->release()['engine_api_version'] ?? $this->get('app.engine_api_version', '1.0'));
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();
        return $this->get($key, $missing) !== $missing;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return $default;
    }

    /** @param array<string, mixed> $value */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target =& $this->items;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target =& $target[$segment];
        }
        $target = $value;
    }

    /** @return array<string, mixed> */
    public function safePublicConfig(): array
    {
        return [
            'app' => [
                'name' => $this->string('app.name', 'CajeerEngine'),
                'env' => $this->string('app.env', 'production'),
                'debug' => $this->bool('app.debug'),
                'url' => $this->string('app.url'),
                'locale' => $this->string('app.locale', 'ru'),
                'version' => $this->string('app.version'),
                'api_version' => $this->string('app.api_version', 'v1'),
                'engine_api_version' => $this->string('app.engine_api_version', '1.0'),
            ],
            'cache' => ['default' => $this->string('cache.default')],
            'database' => ['default' => $this->string('database.default')],
            'queue' => ['default' => $this->string('queue.default')],
            'search' => ['default' => $this->string('search.default')],
            'storage' => ['default' => $this->string('storage.default')],
            'security' => [
                'api_auth_required' => $this->bool('security.api_auth_required', true),
                'protect_content_writes' => $this->bool('security.protect_content_writes', true),
                'protect_system_routes' => $this->bool('security.protect_system_routes', true),
                'rate_limits' => $this->get('security.rate_limits', []),
                'scopes' => $this->get('security.scopes', []),
            ],
            'runtime' => [
                'root_path' => $this->rootPath,
                'booted_at' => $this->string('runtime.booted_at'),
            ],
        ];
    }

    private function loadPhpConfigs(): void
    {
        $configDir = $this->rootPath . '/config';
        if (!is_dir($configDir)) {
            return;
        }

        foreach (glob($configDir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $value = require $file;
            if (is_array($value)) {
                $this->items[$key] = $value;
            }
        }
    }

    private function readVersion(): string
    {
        $versionPath = $this->rootPath . '/VERSION';
        if (is_file($versionPath)) {
            $version = trim((string) file_get_contents($versionPath));
            if ($version !== '') {
                return $version;
            }
        }

        $release = $this->release();
        return (string) ($release['version'] ?? '0.0.0-dev');
    }

    /** @return array<string, mixed> */
    private function release(): array
    {
        $path = $this->rootPath . '/release.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }
}
