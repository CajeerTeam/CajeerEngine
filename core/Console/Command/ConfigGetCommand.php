<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:get', description: 'Показать значение runtime-конфига CajeerEngine. Опасные секреты не выводятся.')]
final class ConfigGetCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::OPTIONAL, 'Ключ в dot notation, например app.name');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'json или text', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $key = (string) ($input->getArgument('key') ?? '');
        $value = $key === '' ? $config->safePublicConfig() : $config->get($key);

        if ($key !== '' && $this->isSecretKey($key)) {
            $output->writeln('Ключ содержит секретное значение и не выводится через config:get.');
            return self::FAILURE;
        }

        if ((string) $input->getOption('format') === 'text') {
            if (is_array($value)) {
                foreach ($value as $itemKey => $itemValue) {
                    $output->writeln($itemKey . ': ' . (is_scalar($itemValue) ? (string) $itemValue : json_encode($itemValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
                }
            } else {
                $output->writeln(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            return self::SUCCESS;
        }

        $output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function isSecretKey(string $key): bool
    {
        return preg_match('/(password|secret|token|key|dsn)/i', $key) === 1 && !str_contains($key, 'api_version');
    }
}
