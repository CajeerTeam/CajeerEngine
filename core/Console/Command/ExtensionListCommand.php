<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:list', description: 'Показать установленные и доступные расширения.')] 
final class ExtensionListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
        $this->addOption('enabled', null, InputOption::VALUE_NONE, 'Показать только включённые');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'module|plugin|theme');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->registry();
        $items = $registry->all();
        $type = (string) ($input->getOption('type') ?? '');
        if ($type !== '') {
            $items = array_values(array_filter($items, fn (array $item): bool => ($item['type'] ?? '') === $type));
        }
        if ((bool) $input->getOption('enabled')) {
            $items = array_values(array_filter($items, fn (array $item): bool => !empty($item['enabled'])));
        }
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => $registry->diagnostics()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln(sprintf(
                '%s %s %s installed=%s enabled=%s valid=%s',
                (string) ($item['type'] ?? ''),
                (string) ($item['name'] ?? ''),
                (string) ($item['version'] ?? ''),
                !empty($item['installed']) ? 'yes' : 'no',
                !empty($item['enabled']) ? 'yes' : 'no',
                !empty($item['valid']) ? 'yes' : 'no',
            ));
        }
        $output->writeln('[OK] Extensions: ' . count($items));
        return self::SUCCESS;
    }

    private function registry(): ExtensionRegistry
    {
        return new ExtensionRegistry($this->rootPath, new ConfigRepository($this->rootPath));
    }
}
