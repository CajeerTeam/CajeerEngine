<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Extension\ExtensionRegistry;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'extension:doctor', description: 'Проверить состояние системы расширений.')] 
final class ExtensionDoctorCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $diagnostics = (new ExtensionRegistry($this->rootPath, new ConfigRepository($this->rootPath)))->diagnostics();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $diagnostics], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($diagnostics as $key => $value) {
            if (is_scalar($value)) {
                $output->writeln($key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
            }
        }
        return !empty($diagnostics['state_writable']) ? self::SUCCESS : self::FAILURE;
    }
}
