<?php

declare(strict_types=1);
namespace CajeerEngine\Console\Command;

use CajeerEngine\Installer\InstallerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install:check', description: 'Проверить готовность окружения к установке CajeerEngine.')]
final class InstallCheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('db', null, InputOption::VALUE_REQUIRED, 'sqlite, pgsql, mysql')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Installation preset')
            ->addOption('json', null, InputOption::VALUE_NONE, 'JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new InstallerService($this->rootPath))->requirements($input->getOption('db') ?: $input->getOption('preset'));
        if ($input->getOption('json')) $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        else {
            foreach ($result['checks'] as $key => $check) {
                $pending = !$result['installed'] && in_array($key, ['env_file', 'app_key'], true) && !$check['ok'];
                $output->writeln(sprintf('[%s] %s', $pending ? 'PENDING' : ($check['ok'] ? 'OK' : 'FAIL'), $check['label']));
            }
            $output->writeln('Ready: ' . ($result['ready'] ? 'yes' : 'no'));
        }
        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
