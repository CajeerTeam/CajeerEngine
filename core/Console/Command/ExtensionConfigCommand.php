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

#[AsCommand(name: 'extension:config', description: 'Показать или изменить конфигурацию расширения.')] 
final class ExtensionConfigCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Имя расширения');
        $this->addArgument('key', InputArgument::OPTIONAL, 'Ключ настройки');
        $this->addArgument('value', InputArgument::OPTIONAL, 'Значение настройки');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $registry = new ExtensionRegistry($this->rootPath, new ConfigRepository($this->rootPath));
            $name = (string) $input->getArgument('name');
            $key = (string) ($input->getArgument('key') ?? '');
            if ($key !== '') {
                $value = $this->parseValue((string) ($input->getArgument('value') ?? ''));
                $item = $registry->updateConfig($name, [$key => $value]);
            } else {
                $item = $registry->findInstalled($name);
                if ($item === null) {
                    throw new \RuntimeException('Расширение не установлено: ' . $name);
                }
            }
            if ((bool) $input->getOption('json')) {
                $output->writeln(json_encode(['data' => $item], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln(json_encode($item['configured'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('[FAIL] ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function parseValue(string $value): mixed
    {
        if ($value === 'true' || $value === 'false') {
            return $value === 'true';
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }
        return $value;
    }
}
