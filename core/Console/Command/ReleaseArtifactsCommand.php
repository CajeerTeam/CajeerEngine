<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Release\ReleaseBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:artifacts', description: 'Собрать source+dist artifacts и GitFlic artifact index.')]
final class ReleaseArtifactsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план без создания ZIP.')
            ->addOption('target-dir', null, InputOption::VALUE_REQUIRED, 'Каталог для artifacts.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target-dir');
        $result = (new ReleaseBuilder($this->rootPath))->buildAll((bool) $input->getOption('dry-run'), is_string($target) ? $target : null);
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('[OK] Source: ' . (string) ($result['source']['target'] ?? ''));
            if (is_array($result['dist'] ?? null)) { $output->writeln('[OK] Dist: ' . (string) $result['dist']['target']); }
            if ($result['dist_error'] ?? null) { $output->writeln('[WARN] Dist не собран: ' . (string) $result['dist_error']); }
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
