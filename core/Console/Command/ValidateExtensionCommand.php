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

#[AsCommand(name: 'extension:validate', description: 'Проверить manifest расширения.')] 
final class ValidateExtensionCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Путь к cajeer.extension.json, директория или имя расширения');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ExtensionRegistry($this->rootPath, new ConfigRepository($this->rootPath)))->validateManifest((string) $input->getArgument('target'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }
        if (!$result['ok']) {
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $output->writeln('[FAIL] ' . $error);
            }
            return self::FAILURE;
        }
        $manifest = $result['manifest'];
        $output->writeln('[OK] Manifest валиден: ' . (string) ($manifest['name'] ?? '') . ' ' . (string) ($manifest['version'] ?? ''));
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            $output->writeln('[WARN] ' . $warning);
        }
        return self::SUCCESS;
    }
}
