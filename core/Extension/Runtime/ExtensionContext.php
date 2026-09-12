<?php

declare(strict_types=1);

namespace CajeerEngine\Extension\Runtime;

use CajeerEngine\Extension\ExtensionManifest;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Runtime\ServiceContainer;

final readonly class ExtensionContext
{
    /** @param array<string,mixed> $state */
    public function __construct(
        public string $rootPath,
        public ExtensionManifest $manifest,
        public array $state,
        public ServiceContainer $container,
        public ConfigRepository $config,
        public EventDispatcher $events,
        public RuntimeLogger $logger,
    ) {
    }

    public function name(): string
    {
        return $this->manifest->name;
    }

    public function slug(): string
    {
        return $this->manifest->slug();
    }

    public function path(): string
    {
        return (string) $this->manifest->path;
    }

    public function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
    }

    /** @return array<string,mixed> */
    public function extensionConfig(): array
    {
        return array_replace_recursive($this->manifest->config, (array) ($this->state['config'] ?? []));
    }

    /** @return array{root:string,extension:string,assets:string,public_assets:string,storage:string,cache:string,logs:string,migrations:string,src:string} */
    public function paths(): array
    {
        $slug = $this->slug();
        return [
            'root' => $this->rootPath,
            'extension' => $this->path(),
            'assets' => $this->path() . '/assets',
            'public_assets' => $this->rootPath . '/public/extensions/' . $slug,
            'storage' => $this->rootPath . '/storage/app/extensions/' . $slug,
            'cache' => $this->rootPath . '/storage/cache/extensions/' . $slug,
            'logs' => $this->rootPath . '/storage/logs/extensions/' . $slug,
            'migrations' => $this->path() . '/migrations',
            'src' => $this->path() . '/src',
        ];
    }

    public function ensureRuntimeDirectories(): void
    {
        foreach ($this->paths() as $key => $path) {
            if (in_array($key, ['assets', 'migrations', 'src', 'extension', 'root'], true)) {
                continue;
            }
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    public function log(string $event, array $payload = []): void
    {
        $this->appendJsonl($this->rootPath . '/storage/app/extensions/runtime.jsonl', [
            'id' => bin2hex(random_bytes(8)),
            'event' => $event,
            'extension' => $this->name(),
            'payload' => $payload,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    public function eventMethodName(string $eventName): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $eventName) ?: [];
        $name = 'on';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $name .= ucfirst($part);
        }
        return $name !== 'on' ? $name : 'onEvent';
    }

    /** @param array<string,mixed> $payload */
    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
