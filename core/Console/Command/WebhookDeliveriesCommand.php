<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Integration\WebhookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'webhook:deliveries', description: 'Показать журнал доставок webhooks.')]
final class WebhookDeliveriesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('webhook-id', null, InputOption::VALUE_REQUIRED, 'Webhook ID', '');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = new WebhookRepository($this->rootPath);
        $items = $repo->deliveries((string) $input->getOption('webhook-id') ?: null);
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode(['data' => $items, 'meta' => ['total' => count($items)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        foreach ($items as $item) {
            $output->writeln(sprintf('%s %s status=%d ok=%s', (string) ($item['created_at'] ?? ''), (string) ($item['event'] ?? ''), (int) ($item['status_code'] ?? 0), !empty($item['ok']) ? 'yes' : 'no'));
        }
        $output->writeln('[OK] Доставок: ' . count($items));
        return self::SUCCESS;
    }
}
