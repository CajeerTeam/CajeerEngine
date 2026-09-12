<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Server\NginxConfigGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'nginx:config', description: 'Сгенерировать production-ready Nginx config для CajeerEngine.')]
final class NginxConfigCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Домен проекта.', 'example.ru')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Preset: default или aapanel.', 'default')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Document root. По умолчанию <project>/public.')
            ->addOption('php-socket', null, InputOption::VALUE_REQUIRED, 'PHP-FPM socket/upstream, например unix:/tmp/php-cgi-84.sock.')
            ->addOption('client-max-body-size', null, InputOption::VALUE_REQUIRED, 'client_max_body_size.', '64m')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Куда записать конфиг.')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Записать конфиг в --output или suggested path.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'domain' => $input->getOption('domain'),
            'preset' => $input->getOption('preset'),
            'root' => $input->getOption('root'),
            'php_socket' => $input->getOption('php-socket'),
            'client_max_body_size' => $input->getOption('client-max-body-size'),
            'output' => $input->getOption('output'),
        ];
        $options = array_filter($options, static fn (mixed $value): bool => $value !== null && $value !== '');
        $generator = new NginxConfigGenerator($this->rootPath);
        $result = (bool) $input->getOption('write') ? $generator->write($options) : $generator->generate($options);
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $io = new SymfonyStyle($input, $output);
        if ((bool) $input->getOption('write')) {
            $io->success('Nginx config записан: ' . (string) ($result['output'] ?? ''));
        }
        $output->writeln((string) $result['config']);
        $io->writeln('Suggested path: ' . (string) ($result['suggested_path'] ?? ''));
        return self::SUCCESS;
    }
}
