<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'content:list', description: 'Показать записи контента из активного content storage.')]
final class ContentListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::OPTIONAL, 'Handle типа контента');
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'draft|published|archived|all', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $typeArg = trim((string) $input->getArgument('type'));
        $status = (string) $input->getOption('status');
        $types = new ContentTypeRepository($this->rootPath);
        $entries = new ContentEntryRepository($this->rootPath);

        $output->writeln('Storage: ' . $entries->storage());
        $handles = $typeArg !== '' ? [$typeArg] : array_map(static fn (array $type): string => (string) $type['handle'], $types->all());
        if ($handles === []) {
            $output->writeln('Типы контента не найдены.');
            return self::SUCCESS;
        }

        foreach ($handles as $handle) {
            $items = $entries->list($handle, ['status' => $status]);
            $output->writeln('[' . $handle . '] ' . count($items) . ' записей');
            foreach ($items as $entry) {
                $output->writeln(sprintf(
                    '- %s | %s | %s | %s | rev %d',
                    (string) ($entry['id'] ?? ''),
                    (string) ($entry['status'] ?? ''),
                    (string) ($entry['locale'] ?? ''),
                    (string) ($entry['title'] ?? ''),
                    (int) ($entry['revision'] ?? 0),
                ));
            }
        }

        return self::SUCCESS;
    }
}
