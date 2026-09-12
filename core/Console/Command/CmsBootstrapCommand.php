<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Cms\CmsBootstrapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cms:bootstrap', description: 'Создать базовый CMS product layer: pages type, theme config, navigation и опциональную главную страницу.')]
final class CmsBootstrapCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('demo-home', null, InputOption::VALUE_NONE, 'Создать опубликованную демо-страницу home, если её нет.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат в JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new CmsBootstrapService($this->rootPath))->run((bool) $input->getOption('demo-home'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }

        $output->writeln('[OK] ' . (string) $result['message']);
        foreach ((array) ($result['created'] ?? []) as $item) {
            $output->writeln('[OK] Создано: ' . (string) $item);
        }
        if (($result['created'] ?? []) === []) {
            $output->writeln('[INFO] Базовые CMS-сущности уже существовали.');
        }
        return self::SUCCESS;
    }
}
