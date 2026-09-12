<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Server\ServerFixerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fix', description: 'Безопасно исправить типовые проблемы окружения CajeerEngine.')]
final class FixCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Применить изменения. Без --yes работает dry-run.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Список действий через запятую: storage,permissions,env,security,admin-assets.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = !(bool) $input->getOption('yes');
        $only = trim((string) $input->getOption('only'));
        $actions = $only !== '' ? array_values(array_filter(array_map('trim', explode(',', $only)))) : null;
        $result = (new ServerFixerService($this->rootPath))->fix($actions, $dryRun);
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }
        $io = new SymfonyStyle($input, $output);
        $io->title($dryRun ? 'CajeerEngine Fix dry-run' : 'CajeerEngine Fix');
        foreach (($result['actions'] ?? []) as $name => $action) {
            $io->writeln(sprintf('[%s] %s', !empty($action['ok']) ? 'OK' : 'FAIL', (string) $name));
        }
        if ($dryRun) {
            $io->warning('Изменения не применены. Добавьте --yes для записи файлов и chmod.');
        }
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
