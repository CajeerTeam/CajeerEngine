<?php

declare(strict_types=1);

namespace CajeerEngine\Console\Command;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentEntryValidator;
use CajeerEngine\Content\ContentTypeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'content:validate', description: 'Проверить записи контента по schema текущих content types.')]
final class ContentValidateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::OPTIONAL, 'Handle типа контента. Если не указан, проверяются все типы.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text или json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $types = new ContentTypeRepository($this->rootPath);
        $entries = new ContentEntryRepository($this->rootPath);
        $typeArg = trim((string) ($input->getArgument('type') ?? ''));
        $selectedTypes = $typeArg !== '' ? array_values(array_filter($types->all(), static fn (array $type): bool => (string) ($type['handle'] ?? '') === $typeArg)) : $types->all();
        $result = [
            'ok' => true,
            'storage' => $entries->storage(),
            'checked' => 0,
            'errors' => [],
        ];

        foreach ($selectedTypes as $type) {
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            foreach ($entries->list($handle, ['status' => 'all']) as $entry) {
                $result['checked']++;
                $errors = (new ContentEntryValidator($type))->errors([
                    'title' => $entry['title'] ?? '',
                    'slug' => $entry['slug'] ?? '',
                    'status' => $entry['status'] ?? 'draft',
                    'locale' => $entry['locale'] ?? 'ru',
                    'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
                ], $entry);
                foreach ($errors as $error) {
                    $result['ok'] = false;
                    $result['errors'][] = [
                        'type' => $handle,
                        'id' => $entry['id'] ?? null,
                        'slug' => $entry['slug'] ?? null,
                        'message' => $error,
                    ];
                }
            }
        }

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $output->writeln(($result['ok'] ? '[OK]' : '[FAIL]') . ' Content validation');
        $output->writeln('Storage: ' . $result['storage']);
        $output->writeln('Checked: ' . $result['checked']);
        foreach ($result['errors'] as $error) {
            $output->writeln(sprintf('[FAIL] %s/%s: %s', (string) $error['type'], (string) ($error['slug'] ?? $error['id']), (string) $error['message']));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
