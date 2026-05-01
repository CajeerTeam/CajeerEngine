<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content\Repository;

use Cajeer\Database\DatabaseManager;
use Cajeer\Modules\Content\Domain\ContentItem;
use PDO;
use Throwable;

final class ContentRepository
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function findPublishedBySlug(string $type, string $slug): ?ContentItem
    {
        try {
            $pdo = $this->database->connection();
            $stmt = $pdo->prepare('SELECT * FROM cajeer_content WHERE type = :type AND slug = :slug AND status = :status LIMIT 1');
            $stmt->execute(['type' => $type, 'slug' => $slug, 'status' => 'published']);
            $row = $stmt->fetch();
            return $row ? ContentItem::fromRow($row) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function find(int $id): ?ContentItem
    {
        $pdo = $this->database->connection();
        $stmt = $pdo->prepare('SELECT * FROM cajeer_content WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? ContentItem::fromRow($row) : null;
    }

    public function latest(string $type = 'post', int $limit = 20): array
    {
        try {
            $limit = max(1, min($limit, 100));
            $pdo = $this->database->connection();
            $stmt = $pdo->prepare('SELECT * FROM cajeer_content WHERE type = :type ORDER BY created_at DESC LIMIT ' . $limit);
            $stmt->execute(['type' => $type]);
            return array_map([ContentItem::class, 'fromRow'], $stmt->fetchAll() ?: []);
        } catch (Throwable) {
            return [];
        }
    }

    public function create(array $data): int
    {
        $pdo = $this->database->connection();
        $slug = $this->uniqueSlug($this->slugify((string) ($data['slug'] ?: $data['title'])));
        $stmt = $pdo->prepare('INSERT INTO cajeer_content (type, status, slug, title, excerpt, body, published_at) VALUES (:type, :status, :slug, :title, :excerpt, :body, :published_at)');
        $stmt->execute([
            'type' => $data['type'] ?: 'post',
            'status' => $data['status'] ?: 'draft',
            'slug' => $slug,
            'title' => $data['title'],
            'excerpt' => $data['excerpt'] ?: null,
            'body' => $data['body'] ?: null,
            'published_at' => ($data['status'] ?? '') === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $current = $this->find($id);
        $slug = $this->slugify((string) ($data['slug'] ?: $data['title']));
        if (!$current || $current->slug !== $slug) {
            $slug = $this->uniqueSlug($slug, $id);
        }
        $pdo = $this->database->connection();
        $stmt = $pdo->prepare('UPDATE cajeer_content SET type = :type, status = :status, slug = :slug, title = :title, excerpt = :excerpt, body = :body, published_at = :published_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'type' => $data['type'] ?: 'post',
            'status' => $data['status'] ?: 'draft',
            'slug' => $slug,
            'title' => $data['title'],
            'excerpt' => $data['excerpt'] ?: null,
            'body' => $data['body'] ?: null,
            'published_at' => ($data['status'] ?? '') === 'published' ? ($current?->publishedAt ?: date('Y-m-d H:i:s')) : null,
        ]);
    }

    public function delete(int $id): void
    {
        $pdo = $this->database->connection();
        $stmt = $pdo->prepare('DELETE FROM cajeer_content WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?: 'content';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'content';
    }

    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $pdo = $this->database->connection();
        $base = $slug;
        $i = 2;
        while (true) {
            $sql = 'SELECT id FROM cajeer_content WHERE slug = :slug';
            $params = ['slug' => $slug];
            if ($ignoreId) {
                $sql .= ' AND id <> :id';
                $params['id'] = $ignoreId;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }
}
