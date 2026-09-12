<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:extension', description: 'Сгенерировать рабочую структуру runtime-расширения.')]
final class GenerateExtensionCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Название расширения, например vendor/news');
        $this->addArgument('type', InputArgument::OPTIONAL, 'module|plugin|theme', 'module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = strtolower(trim((string) $input->getArgument('name')));
        $type = strtolower(trim((string) $input->getArgument('type')));
        if (!in_array($type, ['module', 'plugin', 'theme'], true)) {
            $output->writeln('[FAIL] type должен быть module, plugin или theme.');
            return self::FAILURE;
        }
        if (!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $name)) {
            $output->writeln('[FAIL] name должен быть в формате vendor/name.');
            return self::FAILURE;
        }

        $baseDir = match ($type) {
            'module' => 'modules',
            'plugin' => 'plugins',
            'theme' => 'themes',
        };
        [$vendor, $shortName] = explode('/', $name, 2);
        $className = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $shortName))) . 'Extension';
        $vendorNs = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $vendor)));
        $shortNs = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $shortName)));
        $namespace = 'CajeerExtensions\\' . $vendorNs . '\\' . $shortNs;
        $dir = $this->rootPath . '/' . $baseDir . '/' . $shortName;
        if (is_dir($dir)) {
            $output->writeln('[FAIL] Расширение уже существует: ' . $dir);
            return self::FAILURE;
        }

        foreach (['src', 'config', 'assets', 'migrations'] as $subdir) {
            mkdir($dir . '/' . $subdir, 0775, true);
        }

        file_put_contents($dir . '/README.md', "# {$name}\n\nТип: {$type}.\n\nРабочее runtime-расширение CajeerEngine Engine API ^1.1.\n\n```bash\nphp bin/cajeer extension:install {$name} --enable\nphp bin/cajeer extension:assets {$name}\nphp bin/cajeer extension:event:dispatch content.saved --payload='{}'\n```\n");

        file_put_contents($dir . '/cajeer.extension.json', json_encode([
            'name' => $name,
            'type' => $type,
            'version' => '0.1.0',
            'engine' => '^1.1',
            'title' => $name,
            'description' => 'Runtime-расширение CajeerEngine.',
            'permissions' => ['extension.runtime'],
            'events' => ['kernel.booted', 'content.saved'],
            'hooks' => ['content.saved' => 'onContentSaved'],
            'providers' => [$namespace . '\\' . $className],
            'assets' => ['source' => 'assets', 'public' => true],
            'migrations' => ['path' => 'migrations'],
            'config' => [
                'enabled_by_default' => false,
                'message' => 'Hello from ' . $name,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        file_put_contents($dir . '/config/defaults.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'enabled_by_default' => false,\n    'message' => 'Hello from {$name}',\n];\n");
        file_put_contents($dir . '/assets/admin.js', "console.info('CajeerEngine extension asset loaded: {$name}');\n");

        $migrationStub = <<<'PHP_STUB'
<?php

declare(strict_types=1);

use CajeerEngine\Extension\Runtime\ExtensionContext;

return static function (ExtensionContext $context, ?PDO $pdo = null): void {
    $paths = $context->paths();
    if (!is_dir($paths['storage'])) {
        mkdir($paths['storage'], 0775, true);
    }
    file_put_contents($paths['storage'] . '/migration-marker.txt', 'installed=' . date(DATE_ATOM) . PHP_EOL, FILE_APPEND);
};
PHP_STUB;
        file_put_contents($dir . '/migrations/0001_create_runtime_marker.php', $migrationStub);

        $providerStub = <<<PHP_STUB
<?php

declare(strict_types=1);

namespace {$namespace};

use CajeerEngine\\Extension\\Contracts\\AbstractExtensionProvider;
use CajeerEngine\\Extension\\Runtime\\ExtensionContext;
use CajeerEngine\\Runtime\\RuntimeEvent;
use CajeerEngine\\Runtime\\ServiceContainer;

final class {$className} extends AbstractExtensionProvider
{
    public function register(ServiceContainer \$container, ExtensionContext \$context): void
    {
        \$container->instance('extension.' . \$context->slug() . '.config', \$context->extensionConfig());
    }

    public function boot(ExtensionContext \$context): void
    {
        \$context->log('{$name}.booted', ['config' => \$context->extensionConfig()]);
    }

    public function install(ExtensionContext \$context): void
    {
        \$context->log('{$name}.installed');
    }

    public function uninstall(ExtensionContext \$context): void
    {
        \$context->log('{$name}.uninstalled');
    }

    public function onContentSaved(RuntimeEvent \$event, ExtensionContext \$context): void
    {
        \$context->log('{$name}.content_saved', \$event->payload);
    }
}
PHP_STUB;
        file_put_contents($dir . '/src/' . $className . '.php', $providerStub);

        $output->writeln('[OK] Создано runtime-расширение: ' . $baseDir . '/' . $shortName);
        return self::SUCCESS;
    }
}
