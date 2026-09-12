<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'content:sync-db', description: 'Импортировать file-based content types и entries в database storage 0.4.0.')]
final class ContentSyncDatabaseCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать план без записи в БД.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $database = new DatabaseManager(new ConfigRepository($this->rootPath));
        if (!$database->contentCoreReady()) {
            $output->writeln('[FAIL] Database Content Core не готов. Выполните php bin/cajeer migrate до версии 0.4.0.');
            return self::FAILURE;
        }

        $fileTypes = $this->readJson($this->rootPath . '/storage/app/content-types.json')['content_types'] ?? [];
        if (!is_array($fileTypes)) {
            $fileTypes = [];
        }

        $dbTypes = new ContentTypeRepository($this->rootPath, $database);
        $dbEntries = new ContentEntryRepository($this->rootPath, $database);
        $plannedTypes = 0;
        $plannedEntries = 0;

        foreach ($fileTypes as $type) {
            if (!is_array($type) || !isset($type['handle'])) {
                continue;
            }
            $handle = (string) $type['handle'];
            if ($dbTypes->find($handle) === null) {
                $plannedTypes++;
                if (!$dryRun) {
                    $dbTypes->create($type);
                }
            }

            $fileEntries = $this->readJson($this->rootPath . '/storage/app/content/' . $handle . '.json')['entries'] ?? [];
            if (!is_array($fileEntries)) {
                continue;
            }
            foreach ($fileEntries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $slug = (string) ($entry['slug'] ?? '');
                $locale = (string) ($entry['locale'] ?? 'ru');
                $exists = false;
                foreach ($dbEntries->list($handle, ['status' => 'all', 'locale' => $locale]) as $dbEntry) {
                    if ((string) ($dbEntry['slug'] ?? '') === $slug) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    continue;
                }

                $plannedEntries++;
                if (!$dryRun) {
                    $dbEntries->create($handle, [
                        'title' => $entry['title'] ?? null,
                        'slug' => $entry['slug'] ?? null,
                        'status' => $entry['status'] ?? 'draft',
                        'locale' => $entry['locale'] ?? 'ru',
                        'translation_group' => $entry['translation_group'] ?? ($entry['id'] ?? null),
                        'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
                    ]);
                }
            }
        }

        $output->writeln(($dryRun ? '[DRY-RUN]' : '[OK]') . ' content:sync-db');
        $output->writeln('Content types: ' . $plannedTypes);
        $output->writeln('Entries: ' . $plannedEntries);
        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
