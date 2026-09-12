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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:runtime', description: 'Проверить runtime загрузки расширений, providers и hooks.')]
final class ExtensionRuntimeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('boot', null, InputOption::VALUE_NONE, 'Загрузить и boot включённые расширения');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->runtime();
        $result = (bool) $input->getOption('boot') ? $runtime->boot(true) : $runtime->diagnostics();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return !empty($result['ok']) ? self::SUCCESS : self::FAILURE;
        }
        $output->writeln(sprintf('[%s] Extension runtime: enabled=%d loaded=%d providers=%d', !empty($result['ok']) ? 'OK' : 'WARN', (int) ($result['enabled_extensions'] ?? 0), (int) ($result['loaded_extensions'] ?? 0), (int) ($result['loaded_providers'] ?? 0)));
        foreach ((array) ($result['errors'] ?? []) as $error) {
            $output->writeln('[WARN] ' . json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return !empty($result['ok']) ? self::SUCCESS : self::FAILURE;
    }

    private function runtime(): ExtensionRuntime
    {
        $config = new ConfigRepository($this->rootPath);
        $container = new ServiceContainer();
        $events = new EventDispatcher(new RuntimeLogger($this->rootPath));
        return new ExtensionRuntime($this->rootPath, new ExtensionRegistry($this->rootPath, $config), $container, $config, $events, new RuntimeLogger($this->rootPath), new DatabaseManager($config));
    }
}
