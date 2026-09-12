<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'maintenance', description: 'Показать, включить или отключить maintenance mode.')]
final class MaintenanceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('on', null, InputOption::VALUE_NONE, 'Включить maintenance mode.');
        $this->addOption('off', null, InputOption::VALUE_NONE, 'Отключить maintenance mode.');
        $this->addOption('message', null, InputOption::VALUE_REQUIRED, 'Сообщение maintenance mode.', 'Сайт временно недоступен из-за обслуживания.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = new UpdateManager($this->rootPath, new ConfigRepository($this->rootPath));
        $result = (bool) $input->getOption('on') ? $manager->enableMaintenance((string) $input->getOption('message')) : ((bool) $input->getOption('off') ? $manager->disableMaintenance() : $manager->maintenanceState());
        $output->writeln((bool) $input->getOption('json') ? (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'maintenance: ' . (!empty($result['enabled']) ? 'on' : 'off'));
        return self::SUCCESS;
    }
}
