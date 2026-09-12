<?php

declare(strict_types=1);

namespace CajeerEngine\Audit;

final readonly class AuditLogger
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $event, ?string $actorId = null, array $context = []): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать директорию аудита.');
        }

        $entry = [
            'id' => self::uuid(),
            'event' => $event,
            'actor_id' => $actorId,
            'context' => $context,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function count(): int
    {
        $path = $this->path();
        if (!is_file($path)) {
            return 0;
        }

        $count = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 20): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, max(0, count($lines) - $limit));
        $items = [];
        foreach ($lines as $line) {
            try {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $items[] = $decoded;
                }
            } catch (\JsonException) {
                continue;
            }
        }

        return array_reverse($items);
    }

    private function path(): string
    {
        return $this->rootPath . '/storage/logs/audit.jsonl';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
