<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Stable\StableReleaseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'stable:status', description: 'Показать stable status CajeerEngine 1.1.1.')]
final class StableStatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Сохранить JSON-отчёт в файл.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = new StableReleaseService($this->rootPath, new ConfigRepository($this->rootPath));
        $result = $service->status([]);
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $target = $input->getOption('output');
        if (is_string($target) && $target !== '') {
            $path = str_starts_with($target, '/') ? $target : $this->rootPath . '/' . $target;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $json . PHP_EOL, LOCK_EX);
            $output->writeln('[OK] Отчёт сохранён: ' . $path);
        }
        if ((bool) $input->getOption('json')) {
            $output->writeln($json);
        } else {
            $ok = ($result['ok'] ?? $result['stable'] ?? true) === true;
            $output->writeln('Stable status: ' . ($ok ? 'OK' : 'FAILED'));
            if (isset($result['version'])) { $output->writeln('Version: ' . $result['version']); }
            if (isset($result['target'])) { $output->writeln('Target: ' . $result['target']); }
            if (isset($result['sha256'])) { $output->writeln('SHA256: ' . $result['sha256']); }
            if (isset($result['summary'])) { $output->writeln('Checks: ' . json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        }
        return (($result['ok'] ?? $result['stable'] ?? true) === false) ? self::FAILURE : self::SUCCESS;
    }
}
