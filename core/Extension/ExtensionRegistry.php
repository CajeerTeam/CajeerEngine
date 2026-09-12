<?php

declare(strict_types=1);

namespace CajeerEngine\Extension;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;

final class ExtensionRegistry
{
    private ManifestLoader $loader;
    private CompatibilityChecker $compatibility;
    private ExtensionSignatureVerifier $signatures;

    public function __construct(private readonly string $rootPath, private readonly ConfigRepository $config)
    {
        $this->loader = new ManifestLoader();
        $this->compatibility = new CompatibilityChecker($this->config->string('app.engine_api_version', '1.0'));
        $this->signatures = new ExtensionSignatureVerifier($this->config->bool('extensions.allow_unsigned_in_dev', true));
        $this->ensureDirectories();
    }

    /** @return list<array<string, mixed>> */
    public function discover(): array
    {
        $items = [];
        foreach ($this->paths() as $type => $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob($directory . '/*/' . ManifestLoader::FILENAME) ?: [] as $manifestPath) {
                $items[] = $this->inspectManifest($manifestPath, $type);
            }
        }
        usort($items, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $state = $this->state();
        $available = [];
        foreach ($this->discover() as $item) {
            $available[(string) $item['name']] = $item;
        }

        $items = [];
        foreach ($available as $name => $item) {
            $record = $state['extensions'][$name] ?? null;
            $items[] = array_merge($item, [
                'installed' => is_array($record),
                'enabled' => is_array($record) && !empty($record['enabled']),
                'installed_at' => is_array($record) ? ($record['installed_at'] ?? null) : null,
                'enabled_at' => is_array($record) ? ($record['enabled_at'] ?? null) : null,
                'configured' => is_array($record) ? (array) ($record['config'] ?? []) : [],
                'missing' => false,
            ]);
        }

        foreach (($state['extensions'] ?? []) as $name => $record) {
            if (!is_string($name) || isset($available[$name]) || !is_array($record)) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'slug' => str_replace('/', '--', $name),
                'type' => (string) ($record['type'] ?? 'unknown'),
                'version' => (string) ($record['version'] ?? 'unknown'),
                'engine' => (string) ($record['engine'] ?? ''),
                'title' => (string) ($record['title'] ?? $name),
                'description' => '',
                'permissions' => (array) ($record['permissions'] ?? []),
                'events' => (array) ($record['events'] ?? []),
                'providers' => (array) ($record['providers'] ?? []),
                'path' => (string) ($record['path'] ?? ''),
                'installed' => true,
                'enabled' => !empty($record['enabled']),
                'missing' => true,
                'valid' => false,
                'errors' => ['Файлы расширения отсутствуют.'],
                'warnings' => [],
                'configured' => (array) ($record['config'] ?? []),
            ];
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        return $items;
    }

    /** @return array<string, mixed> */
    public function install(string $target, bool $enable = false): array
    {
        $manifest = $this->manifestByTarget($target);
        $this->compatibility->assertCompatible($manifest);
        $signature = $this->signatureFor($manifest);
        if (!$signature['ok']) {
            throw new \RuntimeException('Подпись расширения не прошла проверку: ' . $signature['status']);
        }

        $state = $this->state();
        $now = $this->now();
        $existing = is_array($state['extensions'][$manifest->name] ?? null) ? $state['extensions'][$manifest->name] : [];
        $state['extensions'][$manifest->name] = array_merge($existing, [
            'name' => $manifest->name,
            'slug' => $manifest->slug(),
            'type' => $manifest->type,
            'version' => $manifest->version,
            'engine' => $manifest->engineConstraint,
            'title' => $manifest->title,
            'path' => $this->relativePath((string) $manifest->path),
            'permissions' => $manifest->permissions,
            'events' => $manifest->events,
            'providers' => $manifest->providers,
            'config' => array_replace_recursive($manifest->config, (array) ($existing['config'] ?? [])),
            'enabled' => $enable || !empty($existing['enabled']),
            'installed_at' => (string) ($existing['installed_at'] ?? $now),
            'updated_at' => $now,
            'enabled_at' => $enable ? $now : ($existing['enabled_at'] ?? null),
            'signature' => $signature,
        ]);
        $this->writeState($state);
        $this->logLifecycle($enable ? 'extension.installed_enabled' : 'extension.installed', $manifest->name, ['version' => $manifest->version]);
        return $this->findInstalled($manifest->name) ?? [];
    }

    /** @return array<string, mixed> */
    public function enable(string $name): array
    {
        $name = $this->resolveName($name);
        $state = $this->state();
        if (!is_array($state['extensions'][$name] ?? null)) {
            $this->install($name, false);
            $state = $this->state();
        }
        $manifest = $this->manifestByTarget($name);
        $this->compatibility->assertCompatible($manifest);
        $state['extensions'][$name]['enabled'] = true;
        $state['extensions'][$name]['enabled_at'] = $this->now();
        $state['extensions'][$name]['updated_at'] = $this->now();
        $this->writeState($state);
        $this->logLifecycle('extension.enabled', $name);
        return $this->findInstalled($name) ?? [];
    }

    /** @return array<string, mixed> */
    public function disable(string $name): array
    {
        $name = $this->resolveName($name);
        $state = $this->state();
        if (!is_array($state['extensions'][$name] ?? null)) {
            throw new \RuntimeException('Расширение не установлено: ' . $name);
        }
        $state['extensions'][$name]['enabled'] = false;
        $state['extensions'][$name]['disabled_at'] = $this->now();
        $state['extensions'][$name]['updated_at'] = $this->now();
        $this->writeState($state);
        $this->logLifecycle('extension.disabled', $name);
        return $this->findInstalled($name) ?? [];
    }

    public function uninstall(string $name): bool
    {
        $name = $this->resolveName($name);
        $state = $this->state();
        if (!isset($state['extensions'][$name])) {
            return false;
        }
        unset($state['extensions'][$name]);
        $this->writeState($state);
        $this->logLifecycle('extension.uninstalled', $name);
        return true;
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function updateConfig(string $name, array $config): array
    {
        $name = $this->resolveName($name);
        $state = $this->state();
        if (!is_array($state['extensions'][$name] ?? null)) {
            throw new \RuntimeException('Расширение не установлено: ' . $name);
        }
        $state['extensions'][$name]['config'] = array_replace_recursive((array) ($state['extensions'][$name]['config'] ?? []), $config);
        $state['extensions'][$name]['updated_at'] = $this->now();
        $this->writeState($state);
        $this->logLifecycle('extension.config_updated', $name, ['keys' => array_keys($config)]);
        return $this->findInstalled($name) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function findInstalled(string $name): ?array
    {
        $name = $this->resolveName($name);
        foreach ($this->all() as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }
        return null;
    }

    /** @return list<array{name:string,extension:string,type:string}> */
    public function permissions(bool $enabledOnly = true): array
    {
        $items = [];
        foreach ($this->all() as $extension) {
            if ($enabledOnly && empty($extension['enabled'])) {
                continue;
            }
            foreach ((array) ($extension['permissions'] ?? []) as $permission) {
                if (is_string($permission) && $permission !== '') {
                    $items[] = ['name' => $permission, 'extension' => (string) $extension['name'], 'type' => (string) $extension['type']];
                }
            }
        }
        return $items;
    }

    /** @return list<array{event:string,extension:string,type:string}> */
    public function eventSubscriptions(bool $enabledOnly = true): array
    {
        $items = [];
        foreach ($this->all() as $extension) {
            if ($enabledOnly && empty($extension['enabled'])) {
                continue;
            }
            foreach ((array) ($extension['events'] ?? []) as $event) {
                if (is_string($event) && $event !== '') {
                    $items[] = ['event' => $event, 'extension' => (string) $extension['name'], 'type' => (string) $extension['type']];
                }
            }
        }
        return $items;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function dispatchEvent(string $event, array $payload = []): array
    {
        $deliveries = [];
        foreach ($this->all() as $extension) {
            if (empty($extension['enabled'])) {
                continue;
            }
            $events = (array) ($extension['events'] ?? []);
            if (!in_array($event, $events, true) && !in_array('*', $events, true)) {
                continue;
            }
            $deliveries[] = [
                'id' => bin2hex(random_bytes(8)),
                'event' => $event,
                'extension' => (string) $extension['name'],
                'status' => 'delivered',
                'payload' => $payload,
                'created_at' => $this->now(),
            ];
        }

        foreach ($deliveries as $delivery) {
            $this->appendJsonl($this->rootPath . '/storage/app/extensions/events.jsonl', $delivery);
        }

        return ['event' => $event, 'delivered' => count($deliveries), 'deliveries' => $deliveries];
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $all = $this->all();
        $installed = array_values(array_filter($all, fn (array $e): bool => !empty($e['installed'])));
        $enabled = array_values(array_filter($all, fn (array $e): bool => !empty($e['enabled'])));
        $invalid = array_values(array_filter($all, fn (array $e): bool => empty($e['valid'])));
        return [
            'driver' => 'file',
            'engine_api_version' => $this->config->string('app.engine_api_version', '1.0'),
            'state_file' => $this->statePath(),
            'state_writable' => is_writable(dirname($this->statePath())),
            'discovered' => count($all),
            'installed' => count($installed),
            'enabled' => count($enabled),
            'invalid' => count($invalid),
            'permissions' => count($this->permissions(false)),
            'enabled_permissions' => count($this->permissions(true)),
            'event_subscriptions' => count($this->eventSubscriptions(false)),
            'enabled_event_subscriptions' => count($this->eventSubscriptions(true)),
            'paths' => array_map(fn (string $path): array => ['path' => $path, 'exists' => is_dir($path), 'writable' => is_dir($path) ? is_writable($path) : is_writable(dirname($path))], $this->paths()),
        ];
    }

    /** @return array<string, mixed> */
    public function validateManifest(string $target): array
    {
        $path = $this->manifestPathForTarget($target);
        try {
            $manifest = $this->loader->load($path);
            $compatible = $this->compatibility->isCompatible($manifest);
            $signature = $this->signatureFor($manifest);
            return [
                'ok' => $compatible && $signature['ok'],
                'manifest' => $manifest->toArray(),
                'compatible' => $compatible,
                'signature' => $signature,
                'errors' => $compatible ? [] : ['Engine API constraint не совместим с текущей версией.'],
                'warnings' => $signature['warnings'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'manifest' => null, 'compatible' => false, 'signature' => null, 'errors' => [$e->getMessage()], 'warnings' => []];
        }
    }

    /** @return array<string, string> */
    public function paths(): array
    {
        $paths = $this->config->get('extensions.paths', []);
        return [
            'module' => $this->rootPath . '/' . trim((string) ($paths['modules'] ?? 'modules'), '/'),
            'plugin' => $this->rootPath . '/' . trim((string) ($paths['plugins'] ?? 'plugins'), '/'),
            'theme' => $this->rootPath . '/' . trim((string) ($paths['themes'] ?? 'themes'), '/'),
        ];
    }

    /** @return array<string, mixed> */
    private function inspectManifest(string $manifestPath, string $expectedType): array
    {
        $validation = $this->validateManifest($manifestPath);
        if (!is_array($validation['manifest'] ?? null)) {
            return [
                'name' => basename(dirname($manifestPath)),
                'slug' => basename(dirname($manifestPath)),
                'type' => $expectedType,
                'path' => $this->relativePath(dirname($manifestPath)),
                'valid' => false,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'installed' => false,
                'enabled' => false,
            ];
        }
        $manifest = $validation['manifest'];
        return array_merge($manifest, [
            'path' => $this->relativePath((string) ($manifest['path'] ?? dirname($manifestPath))),
            'valid' => (bool) $validation['ok'],
            'compatible' => (bool) $validation['compatible'],
            'signature' => $validation['signature'],
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'installed' => false,
            'enabled' => false,
        ]);
    }

    private function manifestByTarget(string $target): ExtensionManifest
    {
        return $this->loader->load($this->manifestPathForTarget($target));
    }

    private function manifestPathForTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            throw new \RuntimeException('Не указано расширение.');
        }
        $direct = $this->rootPath . '/' . ltrim($target, '/');
        if (is_file($direct)) {
            return $direct;
        }
        if (is_dir($direct)) {
            return rtrim($direct, '/') . '/' . ManifestLoader::FILENAME;
        }
        if (is_file($target) || is_dir($target)) {
            return is_dir($target) ? rtrim($target, '/') . '/' . ManifestLoader::FILENAME : $target;
        }

        $name = $this->resolveName($target, false);
        foreach ($this->discover() as $item) {
            if (($item['name'] ?? null) === $name || ($item['slug'] ?? null) === $target) {
                return $this->rootPath . '/' . ltrim((string) $item['path'], '/') . '/' . ManifestLoader::FILENAME;
            }
        }
        throw new \RuntimeException('Расширение не найдено: ' . $target);
    }

    private function resolveName(string $value, bool $mustExist = true): string
    {
        $value = strtolower(trim($value));
        $state = $this->state();
        if (isset($state['extensions'][$value])) {
            return $value;
        }
        foreach ($this->discover() as $item) {
            if (($item['name'] ?? null) === $value || ($item['slug'] ?? null) === $value) {
                return (string) $item['name'];
            }
        }
        if (!$mustExist && preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $value)) {
            return $value;
        }
        if (!$mustExist) {
            return $value;
        }
        throw new \RuntimeException('Расширение не найдено: ' . $value);
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = (new JsonFile($this->statePath()))->readObject(['extensions' => []]);
        if (!isset($state['extensions']) || !is_array($state['extensions'])) {
            $state['extensions'] = [];
        }
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        (new JsonFile($this->statePath()))->writeObject($state);
    }

    private function statePath(): string
    {
        return $this->rootPath . '/storage/app/extensions/registry.json';
    }

    private function signatureFor(ExtensionManifest $manifest): array
    {
        return $this->signatures->verify(((string) $manifest->path) . '/' . ManifestLoader::FILENAME, $manifest);
    }

    private function ensureDirectories(): void
    {
        foreach (['modules', 'plugins', 'themes', 'storage/app/extensions'] as $dir) {
            $path = $this->rootPath . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function logLifecycle(string $event, string $extension, array $payload = []): void
    {
        $this->appendJsonl($this->rootPath . '/storage/app/extensions/lifecycle.jsonl', [
            'id' => bin2hex(random_bytes(8)),
            'event' => $event,
            'extension' => $extension,
            'payload' => $payload,
            'created_at' => $this->now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function appendJsonl(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
