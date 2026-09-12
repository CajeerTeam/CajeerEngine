<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Extension\Runtime\ExtensionRuntime;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\EventDispatcher;
use CajeerEngine\Runtime\RuntimeLogger;
use CajeerEngine\Runtime\ServiceContainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:install', description: 'Установить расширение, запустить lifecycle, migrations и assets.')]
final class ExtensionInstallCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Имя расширения, директория или путь к cajeer.extension.json');
        $this->addOption('enable', null, InputOption::VALUE_NONE, 'Сразу включить расширение');
        $this->addOption('no-lifecycle', null, InputOption::VALUE_NONE, 'Не запускать install() provider hooks');
        $this->addOption('no-assets', null, InputOption::VALUE_NONE, 'Не публиковать assets');
        $this->addOption('no-migrations', null, InputOption::VALUE_NONE, 'Не запускать migrations расширения');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $item = $this->runtime()->install(
                (string) $input->getArgument('target'),
                (bool) $input->getOption('enable'),
                !(bool) $input->getOption('no-lifecycle'),
                !(bool) $input->getOption('no-assets'),
                !(bool) $input->getOption('no-migrations'),
            );
            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode(['data' => $item], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('[OK] Установлено расширение: ' . (string) ($item['name'] ?? ''));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('[FAIL] ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function runtime(): ExtensionRuntime
    {
        $config = new ConfigRepository($this->rootPath);
        $container = new ServiceContainer();
        $events = new EventDispatcher(new RuntimeLogger($this->rootPath));
        return new ExtensionRuntime($this->rootPath, new ExtensionRegistry($this->rootPath, $config), $container, $config, $events, new RuntimeLogger($this->rootPath), new DatabaseManager($config));
    }
}
