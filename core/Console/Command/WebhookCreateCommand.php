<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Integration\WebhookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'webhook:create', description: 'Создать webhook.')]
final class WebhookCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Webhook URL');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Название', 'Webhook');
        $this->addOption('event', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Событие', ['*']);
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $item = (new WebhookRepository($this->rootPath))->create([
            'url' => (string) $input->getArgument('url'),
            'name' => (string) $input->getOption('name'),
            'events' => $input->getOption('event'),
        ]);
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $item], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Webhook создан: ' . $item['id']);
        $output->writeln('[OK] Secret: ' . $item['secret']);
        return self::SUCCESS;
    }
}
