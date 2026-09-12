<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'media:list', description: 'Показывает media library.')]
final class MediaListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('media:list')
            ->setDescription('Показывает media library.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = new MediaRepository($this->rootPath, new StorageManager($this->rootPath, new ConfigRepository($this->rootPath)));
        $items = $repo->all();

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => $repo->diagnostics()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        if ($items === []) {
            $output->writeln('<comment>Media library пуста.</comment>');
            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $output->writeln(sprintf('%s  %s  %s  %s', (string) $item['id'], (string) $item['filename'], (string) $item['mime_type'], (string) $item['url']));
        }

        return self::SUCCESS;
    }
}
