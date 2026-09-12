<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Admin\AdminAssetPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'admin:assets', description: 'Проверить production assets Admin UI и записать manifest.')]
final class AdminAssetsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат в JSON.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Только проверить наличие assets; вернуть ошибку, если assets не готовы.')
            ->addOption('write-manifest', null, InputOption::VALUE_NONE, 'Записать public/admin/assets/admin-manifest.json.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $publisher = new AdminAssetPublisher($this->rootPath);
        $manifest = $publisher->manifest((bool) $input->getOption('write-manifest'));

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('Admin UI production assets: ' . (($manifest['ready'] ?? false) ? 'ready' : 'not ready'));
            foreach (($manifest['files'] ?? []) as $name => $file) {
                $output->writeln(sprintf(
                    '- %s: %s (%s bytes)',
                    (string) $name,
                    !empty($file['exists']) ? 'ok' : 'missing',
                    (string) ($file['size'] ?? 0)
                ));
            }
            if ((bool) $input->getOption('write-manifest')) {
                $output->writeln('[OK] Manifest записан в public/admin/assets/admin-manifest.json');
            }
        }

        if ((bool) $input->getOption('check') && empty($manifest['ready'])) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
