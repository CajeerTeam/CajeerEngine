<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

use CajeerEngine\Support\JsonFile;

final readonly class FileSearchIndex implements SearchEngineInterface
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $document */
    public function index(string $index, string $id, array $document): void
    {
        $data = $this->read();
        $documents = is_array($data['documents'] ?? null) ? $data['documents'] : [];
        $key = $index . ':' . $id;
        $title = (string) ($document['title'] ?? '');
        $body = (string) ($document['body'] ?? '');
        $documents[$key] = [
            'index' => $index,
            'id' => $id,
            'source_id' => $id,
            'type' => (string) ($document['type'] ?? $index),
            'title' => $title,
            'body' => $body,
            'excerpt' => $this->excerpt($body !== '' ? $body : $title),
            'url' => (string) ($document['url'] ?? ''),
            'locale' => (string) ($document['locale'] ?? 'ru'),
            'status' => (string) ($document['status'] ?? 'published'),
            'raw' => is_array($document['raw'] ?? null) ? $document['raw'] : $document,
            'tokens' => $this->tokens($title . ' ' . $body . ' ' . json_encode($document['raw'] ?? [], JSON_UNESCAPED_UNICODE)),
            'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $this->write(['version' => 1, 'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'documents' => $documents]);
    }

    public function delete(string $index, string $id): void
    {
        $data = $this->read();
        $documents = is_array($data['documents'] ?? null) ? $data['documents'] : [];
        unset($documents[$index . ':' . $id]);
        $this->write(['version' => 1, 'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'documents' => $documents]);
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, ?string $index = null, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $needles = $this->tokens($query);
        if ($needles === []) {
            return [];
        }
        $data = $this->read();
        $documents = is_array($data['documents'] ?? null) ? $data['documents'] : [];
        $results = [];
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            if ($index !== null && $index !== '' && (string) ($doc['index'] ?? '') !== $index) {
                continue;
            }
            $tokens = is_array($doc['tokens'] ?? null) ? $doc['tokens'] : [];
            $haystack = implode(' ', array_map('strval', $tokens));
            $score = 0;
            foreach ($needles as $needle) {
                if (in_array($needle, $tokens, true)) {
                    $score += 10;
                } elseif (str_contains($haystack, $needle)) {
                    $score += 3;
                }
            }
            if ($score <= 0) {
                continue;
            }
            $doc['score'] = $score;
            unset($doc['tokens']);
            $results[] = $doc;
        }
        usort($results, static fn (array $a, array $b): int => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)) ?: strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return array_slice($results, 0, max(1, $limit));
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $data = $this->read();
        $documents = is_array($data['documents'] ?? null) ? $data['documents'] : [];
        $byIndex = [];
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $idx = (string) ($doc['index'] ?? 'unknown');
            $byIndex[$idx] = ($byIndex[$idx] ?? 0) + 1;
        }
        ksort($byIndex);
        return [
            'driver' => 'file',
            'path' => $this->path(),
            'documents' => count($documents),
            'indexes' => $byIndex,
            'updated_at' => (string) ($data['updated_at'] ?? ''),
            'writable' => is_writable(dirname($this->path())) || is_writable($this->path()),
        ];
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return (new JsonFile($this->path()))->readObject(['version' => 1, 'documents' => []]);
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        (new JsonFile($this->path()))->writeObject($data);
    }

    private function path(): string
    {
        return $this->rootPath . '/storage/app/search-index.json';
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower(strip_tags($text)) : strtolower(strip_tags($text));
        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', $text) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => (function_exists('mb_strlen') ? mb_strlen($v) : strlen($v)) >= 2));
        return array_values(array_unique($parts));
    }

    private function excerpt(string $body): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
        return function_exists('mb_substr') ? mb_substr($text, 0, 280) : substr($text, 0, 280);
    }
}
