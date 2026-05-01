<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Domain;

final class ContentItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly ?string $body,
        public readonly ?string $publishedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) ($row['type'] ?? 'post'),
            (string) ($row['status'] ?? 'draft'),
            (string) ($row['slug'] ?? ''),
            (string) ($row['title'] ?? ''),
            $row['excerpt'] ?? null,
            $row['body'] ?? null,
            $row['published_at'] ?? null,
        );
    }
}
