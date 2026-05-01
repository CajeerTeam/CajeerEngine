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
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?int $authorId = null,
        public readonly ?int $categoryId = null,
        public readonly string $visibility = 'public',
        public readonly int $sortOrder = 0,
        public readonly ?string $metaTitle = null,
        public readonly ?string $metaDescription = null,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $coverImage = null,
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
            $row['created_at'] ?? null,
            $row['updated_at'] ?? null,
            isset($row['author_id']) ? (int) $row['author_id'] : null,
            isset($row['category_id']) ? (int) $row['category_id'] : null,
            (string) ($row['visibility'] ?? 'public'),
            (int) ($row['sort_order'] ?? 0),
            $row['meta_title'] ?? null,
            $row['meta_description'] ?? null,
            $row['canonical_url'] ?? null,
            $row['cover_image'] ?? null,
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
