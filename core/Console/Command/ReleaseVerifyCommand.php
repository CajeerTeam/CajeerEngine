<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Release\ReleaseBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'release:verify', description: 'Проверить release plan и исключения релизной сборки.')] 
final class ReleaseVerifyCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'JSON')
            ->addOption('source', null, InputOption::VALUE_NONE, 'Source requirements')
            ->addOption('dist', null, InputOption::VALUE_NONE, 'Dist requirements')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Require a locked dependency set')
            ->addOption('artifact', null, InputOption::VALUE_REQUIRED, 'Verify a physical ZIP and sidecars');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new ReleaseBuilder($this->rootPath))->verifyRelease($input->getOption('dist') ? 'dist' : 'source', (bool) $input->getOption('strict'), $input->getOption('artifact'));
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
