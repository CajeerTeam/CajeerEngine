<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:permissions', description: 'Показать permissions, объявленные расширениями.')] 
final class ExtensionPermissionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Показать permissions выключенных расширений тоже');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = (new ExtensionRegistry($this->rootPath, new ConfigRepository($this->rootPath)))->permissions(!(bool) $input->getOption('all'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln(sprintf('%s <- %s', $item['name'], $item['extension']));
        }
        $output->writeln('[OK] Permissions: ' . count($items));
        return self::SUCCESS;
    }
}
