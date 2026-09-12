<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:disable', description: 'Выключить расширение.')] 
final class ExtensionDisableCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Имя расширения vendor/name или slug');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $item = (new ExtensionRegistry($this->rootPath, new ConfigRepository($this->rootPath)))->disable((string) $input->getArgument('name'));
            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode(['data' => $item], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('[OK] Выключено расширение: ' . (string) ($item['name'] ?? ''));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('[FAIL] ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
