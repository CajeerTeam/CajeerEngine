<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Repository;

use Cajeer\Database\DatabaseManager;
use Cajeer\Modules\Content\Domain\ContentItem;
use Throwable;

final class ContentRepository
{
    private const STATUSES = ['draft', 'scheduled', 'published', 'archived', 'deleted'];

    public function __construct(private readonly DatabaseManager $database) {}

    public function findPublishedBySlug(string $type, string $slug): ?ContentItem
    {
        try {
            $stmt = $this->database->connection()->prepare("SELECT * FROM cajeer_content WHERE type = :type AND slug = :slug AND status = 'published' AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP) LIMIT 1");
            $stmt->execute(['type' => $type, 'slug' => $slug]);
            $row = $stmt->fetch();
            return $row ? ContentItem::fromRow($row) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function find(int $id): ?ContentItem
    {
        $stmt = $this->database->connection()->prepare('SELECT * FROM cajeer_content WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? ContentItem::fromRow($row) : null;
    }

    public function latest(string $type = 'post', int $limit = 20, int $offset = 0): array
    {
        try {
            $limit = max(1, min($limit, 100));
            $offset = max(0, $offset);
            $stmt = $this->database->connection()->prepare('SELECT * FROM cajeer_content WHERE type = :type AND status <> :deleted ORDER BY sort_order ASC, created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
            $stmt->execute(['type' => $type, 'deleted' => 'deleted']);
            return array_map([ContentItem::class, 'fromRow'], $stmt->fetchAll() ?: []);
        } catch (Throwable) {
            return [];
        }
    }



    /** @return array<int,ContentItem> */
    public function published(string $type = 'post', int $limit = 100, int $offset = 0): array
    {
        try {
            $limit = max(1, min($limit, 500));
            $offset = max(0, $offset);
            $stmt = $this->database->connection()->prepare("SELECT * FROM cajeer_content WHERE type = :type AND status = 'published' AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP) ORDER BY sort_order ASC, published_at DESC, created_at DESC LIMIT " . $limit . ' OFFSET ' . $offset);
            $stmt->execute(['type' => $type]);
            return array_map([ContentItem::class, 'fromRow'], $stmt->fetchAll() ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    public function count(string $type = 'post'): int
    {
        try {
            $stmt = $this->database->connection()->prepare('SELECT COUNT(*) FROM cajeer_content WHERE type = :type AND status <> :deleted');
            $stmt->execute(['type' => $type, 'deleted' => 'deleted']);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function create(array $data): int
    {
        $pdo = $this->database->connection();
        $slug = $this->uniqueSlug($this->slugify((string) (($data['slug'] ?? '') ?: $data['title'])));
        $status = $this->status((string) ($data['status'] ?? 'draft'));
        $stmt = $pdo->prepare('INSERT INTO cajeer_content (type, status, slug, title, excerpt, body, author_id, category_id, visibility, sort_order, meta_title, meta_description, canonical_url, cover_image, published_at) VALUES (:type, :status, :slug, :title, :excerpt, :body, :author_id, :category_id, :visibility, :sort_order, :meta_title, :meta_description, :canonical_url, :cover_image, :published_at)');
        $stmt->execute($this->params($data, $slug, $status));
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $current = $this->find($id);
        $slug = $this->slugify((string) (($data['slug'] ?? '') ?: $data['title']));
        if (!$current || $current->slug !== $slug) {
            $slug = $this->uniqueSlug($slug, $id);
        }
        $status = $this->status((string) ($data['status'] ?? 'draft'));
        $params = $this->params($data, $slug, $status);
        $params['id'] = $id;
        if ($status === 'published' && empty($params['published_at']) && $current?->publishedAt) {
            $params['published_at'] = $current->publishedAt;
        }
        $stmt = $this->database->connection()->prepare('UPDATE cajeer_content SET type = :type, status = :status, slug = :slug, title = :title, excerpt = :excerpt, body = :body, author_id = :author_id, category_id = :category_id, visibility = :visibility, sort_order = :sort_order, meta_title = :meta_title, meta_description = :meta_description, canonical_url = :canonical_url, cover_image = :cover_image, published_at = :published_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->database->connection()->prepare("UPDATE cajeer_content SET status = 'deleted', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?: 'content';
        return trim($value, '-') ?: 'content';
    }

    private function params(array $data, string $slug, string $status): array
    {
        return [
            'type' => (string) (($data['type'] ?? '') ?: 'post'),
            'status' => $status,
            'slug' => $slug,
            'title' => (string) $data['title'],
            'excerpt' => ($data['excerpt'] ?? '') !== '' ? (string) $data['excerpt'] : null,
            'body' => ($data['body'] ?? '') !== '' ? (string) $data['body'] : null,
            'author_id' => !empty($data['author_id']) ? (int) $data['author_id'] : null,
            'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'visibility' => (string) (($data['visibility'] ?? '') ?: 'public'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'meta_title' => ($data['meta_title'] ?? '') !== '' ? (string) $data['meta_title'] : null,
            'meta_description' => ($data['meta_description'] ?? '') !== '' ? (string) $data['meta_description'] : null,
            'canonical_url' => ($data['canonical_url'] ?? '') !== '' ? (string) $data['canonical_url'] : null,
            'cover_image' => ($data['cover_image'] ?? '') !== '' ? (string) $data['cover_image'] : null,
            'published_at' => $this->publishedAt($status, (string) ($data['published_at'] ?? '')),
        ];
    }

    private function status(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }

    private function publishedAt(string $status, string $value): ?string
    {
        if (in_array($status, ['draft', 'archived', 'deleted'], true)) {
            return null;
        }
        if ($value !== '') {
            return date('Y-m-d H:i:s', strtotime($value) ?: time());
        }
        return $status === 'published' ? date('Y-m-d H:i:s') : null;
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $base = $slug;
        $i = 2;
        while (true) {
            $sql = 'SELECT id FROM cajeer_content WHERE slug = :slug';
            $params = ['slug' => $slug];
            if ($ignoreId) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = $this->database->connection()->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if (!$stmt->fetch()) return $slug;
            $slug = $base . '-' . $i++;
        }
    }
}
