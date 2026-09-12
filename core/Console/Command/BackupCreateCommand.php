<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Stable\StableReleaseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'backup:create', description: 'Создать ZIP backup конфигурации, storage metadata и uploads CajeerEngine.')]
final class BackupCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('no-uploads', null, InputOption::VALUE_NONE, 'Не включать public/uploads.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $result = (new StableReleaseService($this->rootPath, $config))->backup(['include_uploads' => !(bool) $input->getOption('no-uploads')]);
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }
        $io = new SymfonyStyle($input, $output);
        if (($result['ok'] ?? false) === true) {
            $io->success('Backup создан: ' . (string) ($result['target'] ?? ''));
        } else {
            $io->error('Backup не создан: ' . (string) ($result['error'] ?? 'unknown error'));
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
