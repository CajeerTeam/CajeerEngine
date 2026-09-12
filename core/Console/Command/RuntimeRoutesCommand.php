<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Kernel\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'runtime:routes', description: 'Показать реально зарегистрированные HTTP-маршруты runtime.')]
final class RuntimeRoutesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'json или text', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = Application::boot($this->rootPath);
        $routes = $app->routeList();

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln(json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($routes as $route) {
            $output->writeln(sprintf('%-32s %-28s %s', implode('|', $route['methods']), $route['path'], $route['name']));
        }

        return self::SUCCESS;
    }
}
