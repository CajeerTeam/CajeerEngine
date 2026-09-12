<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;

final readonly class SearchIndexer
{
    public function __construct(
        private ContentTypeRepository $types,
        private ContentEntryRepository $entries,
        private SearchEngineInterface $index,
    ) {
    }

    /** @return array<string, mixed> */
    public function indexAll(): array
    {
        $indexed = 0;
        $types = $this->types->all();
        foreach ($types as $type) {
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            foreach ($this->entries->list($handle, ['status' => 'published']) as $entry) {
                $this->indexEntry($handle, $entry);
                $indexed++;
            }
        }
        return ['indexed' => $indexed, 'types' => count($types), 'diagnostics' => $this->index->diagnostics()];
    }

    /** @param array<string, mixed> $entry */
    public function indexEntry(string $typeHandle, array $entry): void
    {
        if (($entry['status'] ?? '') !== 'published') {
            $this->index->delete('content', $typeHandle . ':' . (string) ($entry['id'] ?? ''));
            return;
        }
        $id = $typeHandle . ':' . (string) ($entry['id'] ?? '');
        $title = (string) ($entry['title'] ?? $entry['slug'] ?? $id);
        $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
        $body = $this->flatten($data);
        $slug = (string) ($entry['slug'] ?? '');
        $this->index->index('content', $id, [
            'type' => $typeHandle,
            'title' => $title,
            'body' => $body,
            'url' => $slug !== '' ? '/' . ltrim($slug, '/') : '',
            'locale' => (string) ($entry['locale'] ?? 'ru'),
            'status' => (string) ($entry['status'] ?? 'published'),
            'raw' => [
                'id' => (string) ($entry['id'] ?? ''),
                'type' => $typeHandle,
                'slug' => $slug,
                'revision' => (int) ($entry['revision'] ?? 1),
                'updated_at' => (string) ($entry['updated_at'] ?? ''),
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function flatten(array $data): string
    {
        $parts = [];
        $walk = static function (mixed $value) use (&$walk, &$parts): void {
            if (is_scalar($value)) {
                $parts[] = (string) $value;
                return;
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child);
                }
            }
        };
        $walk($data);
        return implode(' ', $parts);
    }
}
