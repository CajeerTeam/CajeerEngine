<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\UpdateResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update:check', description: 'Проверить обновления ядра через GitFlic Releases / Registry metadata.')]
final class UpdateCheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new ConfigRepository($this->rootPath);
        $result = (new UpdateResolver($config->get('updates', []), $this->rootPath))->check($config->string('app.version', '1.1.1'));
        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('Current: ' . $result['current_version']);
        $output->writeln('Channel: ' . $result['channel']);
        $output->writeln('Source: ' . $result['metadata_source']);
        $output->writeln('Update: ' . ($result['update_available'] ? 'yes' : 'no'));
        if (is_array($result['latest'] ?? null)) {
            $output->writeln('Latest: ' . $result['latest']['version']);
        }
        return self::SUCCESS;
    }
}
