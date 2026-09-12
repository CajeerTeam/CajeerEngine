<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'serve', description: 'Запустить встроенный PHP dev server.')]
final class ServeCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Запуск: php -S 127.0.0.1:8080 -t public public/index.php');
        passthru('php -S 127.0.0.1:8080 -t ' . escapeshellarg($this->rootPath . '/public') . ' ' . escapeshellarg($this->rootPath . '/public/index.php'));
        return self::SUCCESS;
    }
}
