<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Security\SignedPayload;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'webhook:dispatch', description: 'Отправить событие во все подходящие webhooks.')]
final class WebhookDispatchCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('event', InputArgument::REQUIRED, 'Имя события');
        $this->addOption('payload', null, InputOption::VALUE_REQUIRED, 'JSON payload', '{}');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = json_decode((string) $input->getOption('payload'), true);
        if (!is_array($payload)) {
            $output->writeln('<error>payload должен быть JSON-объектом.</error>');
            return self::FAILURE;
        }
        $repo = new WebhookRepository($this->rootPath);
        $result = (new WebhookDispatcher(new SignedPayload(), $repo))->dispatchEvent((string) $input->getArgument('event'), $payload);
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        $output->writeln('[OK] Доставок: ' . (int) ($result['deliveries'] ?? 0));
        return self::SUCCESS;
    }
}
