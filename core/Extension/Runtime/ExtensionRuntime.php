<?php

declare(strict_types=1);

namespace CajeerEngine\Extension\Runtime;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\Contracts\ExtensionProviderInterface;
use CajeerEngine\Extension\ExtensionManifest;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Extension\ManifestLoader;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\RuntimeEvent;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Runtime\ServiceContainer;
use CajeerEngine\Support\JsonFile;

final class ExtensionRuntime
{
    /** @var array<string,list<object>> */
    private array $providers = [];

    /** @var array<string,ExtensionContext> */
    private array $contexts = [];

    private bool $booted = false;
    private bool $registered = false;
    private ManifestLoader $loader;
    private PlatformServices $platform;
    public function platform(): PlatformServices { return $this->platform; }

    public function __construct(
        private readonly string $rootPath,
        private readonly ExtensionRegistry $registry,
        private readonly ServiceContainer $container,
        private readonly ConfigRepository $config,
        private readonly EventDispatcher $events,
        private readonly RuntimeLogger $logger,
        private readonly ?DatabaseManager $database = null,
    ) {
        $this->loader = new ManifestLoader();
        $this->platform = new PlatformServices();
    }

    /** @return array<string,mixed> */
    public function boot(bool $force = false): array
    {
        if ($this->booted) {
            return $this->diagnostics();
        }
        $this->registered = false;
        $this->providers = [];
        $this->contexts = [];

        $extensions = \CajeerEngine\Extension\DependencyGraph::order($this->registry->all());

        foreach ($extensions as $extension) {
            $this->loadExtension($extension, 'register');
        }
        $this->registered = true;

        foreach ($this->providers as $name => $providers) {
            $context = $this->contexts[$name];
            foreach ($providers as $provider) {
                $this->callProviderLifecycle($provider, 'boot', $context);
            }
            $this->registerEventListeners($context);
            $context->log('extension.booted', ['providers' => count($providers)]);
        }

        $this->booted = true;
        return $this->diagnostics();
    }

    /** @return array<string,mixed> */
    public function install(string $target, bool $enable = false, bool $runLifecycle = true, bool $publishAssets = true, bool $runMigrations = true): array
    {
        $item = $this->registry->install($target, false);
        if ($runLifecycle) {
            $manifest = $this->manifestFromItem($item);
            $context = $this->context($manifest, $item);
            $providers = $this->providerInstances($manifest, $context);
            foreach ($providers as $provider) {
                $this->callProviderLifecycle($provider, 'install', $context);
            }
            if ($runMigrations) {
                $this->migrate($manifest->name, false);
            }
            if ($publishAssets) {
                $this->publishAssets($manifest->name, false);
            }
            $context->log('extension.install_lifecycle_completed');
        }
        if ($enable) $this->registry->enable((string)$item['name']);
        return $this->registry->findInstalled((string) ($item['name'] ?? $target)) ?? $item;
    }

    public function upgradeLifecycle(string $name,string $previousVersion): void
    {
        $item=$this->registry->findInstalled($name)??throw new \RuntimeException('Extension missing');
        $manifest=$this->manifestFromItem($item); $context=$this->context($manifest,$item);
        foreach ($this->providerInstances($manifest,$context) as $provider) if (method_exists($provider,'upgrade')) $provider->upgrade($context,$previousVersion);
        $context->log('extension.upgraded',['previous'=>$previousVersion,'version'=>$manifest->version]);
    }

    /** @return array<string,mixed> */
    public function uninstall(string $name, bool $runLifecycle = true, bool $removeAssets = false): array
    {
        $item = $this->registry->findInstalled($name);
        if ($item === null || empty($item['installed'])) {
            return ['ok' => true, 'deleted' => false, 'message' => 'Расширение не было установлено.'];
        }
        if (!empty($item['enabled'])) $this->registry->disable((string)$item['name']);
        if ($runLifecycle && empty($item['missing']) && !empty($item['valid'])) {
            $manifest = $this->manifestFromItem($item);
            $context = $this->context($manifest, $item);
            foreach ($this->providerInstances($manifest, $context) as $provider) {
                $this->callProviderLifecycle($provider, 'uninstall', $context);
            }
            $context->log('extension.uninstall_lifecycle_completed');
        }
        $deleted = $this->registry->uninstall((string) $item['name']);
        if ($removeAssets) {
            $this->removeAssets((string) $item['slug']);
        }
        return ['ok' => true, 'deleted' => $deleted, 'removed_assets' => $removeAssets];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function dispatch(string $event, array $payload = []): array
    {
        $this->boot();
        $runtimeEvent = new RuntimeEvent($event, $payload);
        $this->logger->event($runtimeEvent, ['source' => 'extension-runtime']);

        $deliveries = [];
        foreach ($this->providers as $name => $providers) {
            $context = $this->contexts[$name];
            $manifestEvents = array_merge($context->manifest->events, array_keys((array) ($context->manifest->raw['hooks'] ?? [])));
            if (!in_array($event, $manifestEvents, true) && !in_array('*', $manifestEvents, true)) {
                continue;
            }
            foreach ($providers as $provider) {
                $started = microtime(true);
                try {
                    $this->callProviderEvent($provider, $runtimeEvent, $context);
                    $status = 'delivered';
                    $error = null;
                } catch (\Throwable $e) {
                    $status = 'failed';
                    $error = $e->getMessage();
                }
                $delivery = [
                    'id' => bin2hex(random_bytes(8)),
                    'event' => $event,
                    'extension' => $name,
                    'provider' => $provider::class,
                    'status' => $status,
                    'error' => $error,
                    'duration_ms' => round((microtime(true) - $started) * 1000, 3),
                    'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
                $deliveries[] = $delivery;
                $this->appendJsonl($this->rootPath . '/storage/app/extensions/events.jsonl', $delivery + ['payload' => $payload]);
            }
        }

        return [
            'ok' => !array_filter($deliveries, static fn (array $d): bool => ($d['status'] ?? '') === 'failed'),
            'event' => $event,
            'delivered' => count(array_filter($deliveries, static fn (array $d): bool => ($d['status'] ?? '') === 'delivered')),
            'failed' => count(array_filter($deliveries, static fn (array $d): bool => ($d['status'] ?? '') === 'failed')),
            'deliveries' => $deliveries,
        ];
    }

    /** @return array<string,mixed> */
    public function publishAssets(?string $name = null, bool $dryRun = false): array
    {
        $items = $name !== null ? array_filter($this->registry->all(), fn (array $item): bool => ($item['name'] ?? '') === $this->resolveInstalledName($name)) : $this->registry->all();
        $published = [];
        foreach ($items as $item) {
            if (empty($item['installed'])) continue;
            if (empty($item['valid']) || !empty($item['missing'])) throw new \RuntimeException('Invalid installed extension: '.$item['name']);
            $manifest = $this->manifestFromItem($item);
            $context = $this->context($manifest, $item);
            $paths = $context->paths();
            $source = $paths['assets'];
            $target = $paths['public_assets'];
            $files = [];
            if (!$dryRun) $this->removeAssets($manifest->slug());
            if (is_dir($source)) {
                foreach ($this->files($source) as $file) {
                    $relative = substr(str_replace('\\', '/', $file), strlen(rtrim(str_replace('\\', '/', $source), '/')) + 1);
                    $files[] = $relative;
                    if (!$dryRun) {
                        $destination = $target . '/' . $relative;
                        if (!is_dir(dirname($destination))) {
                            @mkdir(dirname($destination), 0775, true);
                        }
                        \CajeerEngine\Update\OperationFiles::copy($file, \CajeerEngine\Update\OperationFiles::path($this->rootPath, $this->relativePath($destination)),0644);
                    }
                }
            }
            if (!$dryRun && $files !== []) {
                (new JsonFile($target . '/manifest.json'))->writeObject([
                    'extension' => $manifest->name,
                    'version' => $manifest->version,
                    'files' => $files,
                    'published_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ]);
            }
            $published[] = ['extension' => $manifest->name, 'source' => $source, 'target' => $target, 'files' => count($files), 'dry_run' => $dryRun];
        }
        return ['ok' => true, 'data' => $published, 'meta' => ['total' => count($published)]];
    }

    /** @return array<string,mixed> */
    public function migrate(?string $name = null, bool $dryRun = false): array
    {
        $items = $name !== null ? array_filter($this->registry->all(), fn (array $item): bool => ($item['name'] ?? '') === $this->resolveInstalledName($name)) : $this->registry->all();
        $ledger=new MigrationLedger($this->rootPath,$this->config,$this->database);
        $results = [];
        foreach ($items as $item) {
            if (empty($item['installed'])) continue;
            if (empty($item['valid']) || !empty($item['missing'])) throw new \RuntimeException('Invalid installed extension: '.$item['name']);
            $manifest = $this->manifestFromItem($item);
            $context = $this->context($manifest, $item);
            $directory = $context->paths()['migrations'];
            $applied = $ledger->applied($manifest->name);
            $pending = [];
            foreach ($applied as $id => $sha) { if (is_int($id)) throw new \RuntimeException('Legacy migration history needs explicit review'); $old=$directory.'/'.$id; if (!is_file($old)||!hash_equals($sha,hash_file('sha256',$old))) throw new \RuntimeException('Extension migration drift: '.$id); }
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $id = basename($file);
                if (!array_key_exists($id, $applied)) {
                    $pending[$id] = $file;
                }
            }
            $run = [];
            foreach ($pending as $id => $file) {
                if (!$dryRun) {
                    $migration = require $file;
                    if (is_array($migration)) $migration=$migration['up']??null;
                    if (!is_callable($migration)) throw new \RuntimeException('Migration must return callable: '.$file);
                    $sha=hash_file('sha256',$file);
                    $ledger->run($manifest->name,$id,$sha,$migration,$context);
                    $applied[$id]=$sha;
                }
                $run[] = $id;
            }
            $results[] = [
                'extension' => $manifest->name,
                'directory' => $directory,
                'applied' => count($applied),
                'pending_before' => count($pending),
                'run' => $run,
                'dry_run' => $dryRun,
            ];
        }
        return ['ok' => true, 'data' => $results, 'meta' => ['total' => count($results)]];
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        $all = $this->registry->all();
        $enabled = array_values(array_filter($all, static fn (array $e): bool => !empty($e['enabled']) && !empty($e['installed'])));
        $providers = 0;
        $bootErrors = [];
        foreach ($enabled as $item) {
            $providers += count((array) ($item['providers'] ?? []));
            if (!empty($item['missing']) || empty($item['valid'])) {
                $bootErrors[] = ['extension' => $item['name'] ?? '', 'reason' => !empty($item['missing']) ? 'missing' : 'invalid'];
            }
        }
        return [
            'ok' => $bootErrors === [],
            'booted' => $this->booted,
            'registered' => $this->registered,
            'engine_api_version' => $this->config->string('app.engine_api_version', '1.1'),
            'enabled_extensions' => count($enabled),
            'loaded_extensions' => count($this->providers),
            'declared_providers' => $providers,
            'loaded_providers' => array_sum(array_map('count', $this->providers)),
            'pages' => $this->platform->pages(),
            'tasks' => $this->platform->tasks(),
            'listener_counts' => $this->events->listenerCounts(),
            'errors' => $bootErrors,
            'assets_root' => 'public/extensions',
            'migrations_state' => 'ce_extension_migrations (development fallback: storage/app/extensions/migrations.json)',
        ];
    }

    /** @param array<string,mixed> $item */
    private function loadExtension(array $item, string $stage): void
    {
        $manifest = $this->manifestFromItem($item);
        $context = $this->context($manifest, $item);
        $providers = $this->providerInstances($manifest, $context);
        foreach ($providers as $provider) {
            $this->callProviderLifecycle($provider, 'register', $context);
        }
        $this->providers[$manifest->name] = $providers;
        $this->contexts[$manifest->name] = $context;
        $context->log('extension.registered', ['providers' => count($providers), 'stage' => $stage]);
    }

    private function registerEventListeners(ExtensionContext $context): void
    {
        $events = array_values(array_unique(array_merge($context->manifest->events, array_keys((array) ($context->manifest->raw['hooks'] ?? [])))));
        foreach ($events as $eventName) {
            if (!is_string($eventName) || $eventName === '') {
                continue;
            }
            $this->events->listen($eventName, function (RuntimeEvent $event) use ($context): void {
                foreach ($this->providers[$context->manifest->name] ?? [] as $provider) {
                    try {
                        $this->callProviderEvent($provider, $event, $context);
                        $context->log('extension.event_handled', ['event' => $event->name, 'provider' => $provider::class]);
                    } catch (\Throwable $e) {
                        $context->log('extension.event_failed', ['event' => $event->name, 'provider' => $provider::class, 'error' => $e->getMessage()]);
                    }
                }
            });
        }
    }

    /** @return list<object> */
    private function providerInstances(ExtensionManifest $manifest, ExtensionContext $context): array
    {
        $this->autoloadExtension($manifest);
        $providers = [];
        foreach ($manifest->providers as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(sprintf('Provider %s не найден для расширения %s.', $class, $manifest->name));
            }
             $reflection=new \ReflectionClass($class); $file=$reflection->getFileName(); if (!is_string($file)||!str_starts_with(realpath($file)?:'',realpath((string)$manifest->path).DIRECTORY_SEPARATOR)) throw new \RuntimeException('Provider class outside package: '.$class);
            $instance = new $class();
            if (!is_object($instance)) {
                throw new \RuntimeException('Provider должен быть объектом: ' . $class);
            }
            $providers[] = $instance;
        }
        if ($providers === [] && is_file($manifest->path . '/bootstrap.php')) {
            $bootstrap = require $manifest->path . '/bootstrap.php';
            if (is_callable($bootstrap)) {
                $bootstrap($this->container, $context);
            }
        }
        return $providers;
    }

    private function callProviderLifecycle(object $provider, string $method, ExtensionContext $context): void
    {
        if ($provider instanceof ExtensionProviderInterface) {
            if ($method === 'register') {
                $provider->register($this->container, $context);
            } elseif ($method === 'boot') {
                $provider->boot($context);
            } elseif ($method === 'install') {
                $provider->install($context);
            } elseif ($method === 'uninstall') {
                $provider->uninstall($context);
            }
            return;
        }
        if (method_exists($provider, $method)) {
            if ($method === 'register') {
                $provider->{$method}($this->container, $context);
            } else {
                $provider->{$method}($context);
            }
        }
    }

    private function callProviderEvent(object $provider, RuntimeEvent $event, ExtensionContext $context): void
    {
        if ($provider instanceof ExtensionProviderInterface) {
            $provider->onEvent($event, $context);
            return;
        }
        $method = $context->eventMethodName($event->name);
        if (method_exists($provider, $method)) {
            $provider->{$method}($event, $context);
            return;
        }
        if (method_exists($provider, 'onEvent')) {
            $provider->onEvent($event, $context);
        }
    }

    private function autoloadExtension(ExtensionManifest $manifest): void
    {
        $autoload = $manifest->path . '/autoload.php';
        if (is_file($autoload)) { require_once $autoload; return; }
        $src = $manifest->path . '/src';
        foreach ($manifest->providers as $class) {
            $parts=explode('\\',$class); $file=$src.'/'.end($parts).'.php';
            if (is_file($file)) require_once $file;
        }
    }

    /** @param array<string,mixed> $item */
    private function manifestFromItem(array $item): ExtensionManifest
    {
        if (empty($item['valid']) || empty($item['installed'])) throw new \RuntimeException('Invalid extension code or state');
        $path = $this->rootPath . '/' . ltrim((string) ($item['path'] ?? ''), '/');
        return $this->loader->load($path . '/' . ManifestLoader::FILENAME);
    }

    /** @param array<string,mixed> $state */
    private function context(ExtensionManifest $manifest, array $state): ExtensionContext
    {
        $context = new ExtensionContext($this->rootPath, $manifest, $state, $this->container, $this->config, $this->events, $this->logger, $this->platform);
        $context->ensureRuntimeDirectories();
        return $context;
    }

    private function resolveInstalledName(string $name): string
    {
        $item = $this->registry->findInstalled($name);
        if ($item === null) {
            throw new \RuntimeException('Расширение не установлено: ' . $name);
        }
        return (string) $item['name'];
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $result[] = $file->getPathname();
            }
        }
        sort($result);
        return $result;
    }

    /** @return array<string,list<string>> */
    private function migrationState(): array
    {
        return (new JsonFile($this->migrationStatePath()))->readObject([]);
    }

    /** @param array<string,list<string>> $state */
    private function writeMigrationState(array $state): void
    {
        (new JsonFile($this->migrationStatePath()))->writeObject($state);
    }

    private function migrationStatePath(): string
    {
        return $this->rootPath . '/storage/app/extensions/migrations.json';
    }

    private function removeAssets(string $slug): void
    {
        $path = $this->rootPath . '/public/extensions/' . $slug;
        if (is_link($path)) throw new \RuntimeException('Symlink assets root');
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                if ($file->isLink()) throw new \RuntimeException('Symlink assets entry');
                if ($file->isDir() ? !rmdir($file->getPathname()) : !unlink($file->getPathname())) throw new \RuntimeException('Cannot remove asset');
            }
        }
        if (!rmdir($path)) throw new \RuntimeException('Cannot remove assets directory');
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

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
    }
}
