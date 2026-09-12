<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Release\ReleaseBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:build', description: 'Собрать source/dist release ZIP, SHA256 и manifest artifacts.')]
final class ReleaseBuildCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план без создания ZIP.')
            ->addOption('dist', null, InputOption::VALUE_NONE, 'Собрать deployable dist archive.')
            ->addOption('source', null, InputOption::VALUE_NONE, 'Собрать source archive.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Собрать source и dist artifacts.')
            ->addOption('target-dir', null, InputOption::VALUE_REQUIRED, 'Каталог для artifacts.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $builder = new ReleaseBuilder($this->rootPath);
        $targetDir = $input->getOption('target-dir');
        if ((bool) $input->getOption('all')) {
            $result = $builder->buildAll((bool) $input->getOption('dry-run'), is_string($targetDir) ? $targetDir : null);
        } else {
            $mode = (bool) $input->getOption('dist') ? 'dist' : 'source';
            if ((bool) $input->getOption('source')) { $mode = 'source'; }
            $result = $builder->build((bool) $input->getOption('dry-run'), $mode, is_string($targetDir) ? $targetDir : null);
        }
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? true) ? self::SUCCESS : self::FAILURE;
        }
        if (($result['source'] ?? null) !== null) {
            $output->writeln('[OK] Source: ' . (string) ($result['source']['target'] ?? ''));
            $output->writeln(is_array($result['dist'] ?? null) ? '[OK] Dist: ' . (string) $result['dist']['target'] : '[WARN] Dist: ' . (string) ($result['dist_error'] ?? 'not built'));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }
        $output->writeln('[OK] Version: ' . $result['version']);
        $output->writeln('[OK] Mode: ' . $result['mode']);
        $output->writeln('[OK] Files: ' . $result['file_count']);
        $output->writeln('[OK] Target: ' . $result['target']);
        if (!empty($result['sha256'])) { $output->writeln('[OK] SHA256: ' . $result['sha256']); }
        if (!empty($result['checksum_file'])) { $output->writeln('[OK] Checksum: ' . $result['checksum_file']); }
        return ($result['ok'] ?? true) ? self::SUCCESS : self::FAILURE;
    }
}
