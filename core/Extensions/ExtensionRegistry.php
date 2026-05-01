<?php

declare(strict_types=1);

namespace Cajeer\Extensions;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class ExtensionRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $extensions = [];

    public function __construct(private readonly string $basePath, private readonly EventDispatcher $events) {}

    public function discoverAndRegister(Container $container): void
    {
        foreach (['modules', 'plugins', 'themes'] as $type) {
            $this->discoverDirectory($this->basePath . '/' . $type, $type, $container);
        }
    }

    public function all(): array
    {
        return $this->extensions;
    }

    private function discoverDirectory(string $path, string $type, Container $container): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*/extension.json') ?: [] as $manifestFile) {
            $manifest = json_decode(file_get_contents($manifestFile) ?: '{}', true);
            if (!is_array($manifest) || empty($manifest['name'])) {
                continue;
            }

            $manifest['type'] = $manifest['type'] ?? rtrim($type, 's');
            $manifest['path'] = dirname($manifestFile);
            $this->extensions[$manifest['name']] = $manifest;

            $provider = $manifest['provider'] ?? null;
            if (is_string($provider) && class_exists($provider)) {
                $instance = new $provider();
                if (method_exists($instance, 'register')) {
                    $instance->register($container, $this->events, $manifest);
                }
            }
        }
    }
}
