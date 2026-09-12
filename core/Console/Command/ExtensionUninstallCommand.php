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

#[AsCommand(name: 'extension:uninstall', description: 'Удалить расширение из registry с lifecycle uninstall().')]
final class ExtensionUninstallCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Имя расширения vendor/name или slug');
        $this->addOption('no-lifecycle', null, InputOption::VALUE_NONE, 'Не вызывать uninstall() provider hooks');
        $this->addOption('remove-assets', null, InputOption::VALUE_NONE, 'Удалить опубликованные public/extensions assets');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->runtime()->uninstall((string) $input->getArgument('name'), !(bool) $input->getOption('no-lifecycle'), (bool) $input->getOption('remove-assets'));
            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln(!empty($result['deleted']) ? '[OK] Расширение удалено из registry.' : '[OK] Расширение не было установлено.');
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
