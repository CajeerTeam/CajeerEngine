<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Integration\WebhookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'webhook:list', description: 'Показать webhooks.')]
final class WebhookListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = new WebhookRepository($this->rootPath);
        $items = $repo->all();
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => $repo->diagnostics()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln(sprintf('%s %s %s enabled=%s', (string) ($item['id'] ?? ''), (string) ($item['name'] ?? ''), (string) ($item['url'] ?? ''), !empty($item['enabled']) ? 'yes' : 'no'));
        }
        $output->writeln('[OK] Webhooks: ' . count($items));
        return self::SUCCESS;
    }
}
