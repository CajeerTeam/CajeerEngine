<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Release\ReleaseBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:checksums', description: 'Проверить SHA256-файлы release artifacts.')]
final class ReleaseChecksumsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('target-dir', null, InputOption::VALUE_REQUIRED, 'Каталог с artifacts.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target-dir');
        $result = (new ReleaseBuilder($this->rootPath))->verifyArtifacts(is_string($target) ? $target : null);
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['checks'] as $mode => $check) {
                $output->writeln('[' . (($check['valid'] ?? false) ? 'OK' : 'FAIL') . '] ' . $mode . ' — ' . (string) ($check['artifact'] ?? ''));
            }
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
