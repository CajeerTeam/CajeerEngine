<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Queue\QueueWorker;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Search\SearchFactory;
use CajeerEngine\Search\SearchIndexer;
use CajeerEngine\Security\SignedPayload;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'queue:work', description: 'Обработать задачи file-based очереди CajeerEngine.')]
final class QueueWorkCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Очередь', 'default');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум задач за запуск', '10');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $worker = $this->worker();
        $result = $worker->work((string) $input->getOption('queue'), (int) $input->getOption('limit'));
        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }
        $output->writeln('[OK] Обработано задач: ' . (int) ($result['processed'] ?? 0));
        $output->writeln('[OK] Ошибок: ' . (int) ($result['failed'] ?? 0));
        $output->writeln('[OK] Неподтверждённых резервирований: ' . (int) ($result['lost_reservations'] ?? 0));
        if ($result['backend_unavailable'] ?? false) $output->writeln('[ERROR] Queue backend unavailable');
        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function worker(): QueueWorker
    {
        return \CajeerEngine\Kernel\Application::boot($this->rootPath)->container()->get(QueueWorker::class);
    }
}
