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

#[AsCommand(name: 'extension:event:dispatch', description: 'Выполнить событие через runtime hooks включённых расширений.')]
final class ExtensionEventDispatchCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('event', InputArgument::REQUIRED, 'Название события');
        $this->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON payload', '{}');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $payload = json_decode((string) $input->getOption('payload'), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                $payload = [];
            }
            $result = $this->runtime()->dispatch((string) $input->getArgument('event'), $payload);
            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode(['data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln(sprintf('[OK] Event %s delivered=%d failed=%d.', $result['event'], $result['delivered'], $result['failed']));
            }
            return !empty($result['ok']) ? self::SUCCESS : self::FAILURE;
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
