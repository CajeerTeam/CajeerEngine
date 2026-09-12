<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;
use PDO;
use Throwable;

final class ContentEntryRepository
{
    private ContentTypeRepository $types;
    private AuditLogger $audit;
    private ?DatabaseManager $database = null;
    private ?bool $databaseReady = null;

    public function __construct(private readonly string $rootPath, ?DatabaseManager $database = null)
    {
        $this->database = $database;
        $this->types = new ContentTypeRepository($this->rootPath, $database);
        $this->audit = new AuditLogger($this->rootPath);
    }

    public function storage(): string
    {
        return $this->useDatabase() ? 'database' : 'file';
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function list(string $typeHandle, array $filters = []): array
    {
        if ($this->useDatabase()) {
            return $this->listFromDatabase($typeHandle, $filters);
        }

        $this->contentType($typeHandle);
        $items = $this->read($typeHandle)['entries'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $status = (string) ($filters['status'] ?? 'all');
        $locale = (string) ($filters['locale'] ?? '');
        $query = self::lower(trim((string) ($filters['q'] ?? '')));
        $publishedOnly = filter_var($filters['published_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $items = array_values(array_filter($items, static function (array $entry) use ($status, $locale, $query, $publishedOnly): bool {
            if ($publishedOnly && ($entry['status'] ?? null) !== 'published') {
                return false;
            }
            if ($status !== 'all' && $status !== '' && ($entry['status'] ?? null) !== $status) {
                return false;
            }
            if ($locale !== '' && ($entry['locale'] ?? null) !== $locale) {
                return false;
            }
            if ($query !== '') {
                $haystack = self::lower((string) ($entry['title'] ?? '') . ' ' . (string) ($entry['slug'] ?? '') . ' ' . json_encode($entry['data'] ?? [], JSON_UNESCAPED_UNICODE));
                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }
            return true;
        }));

        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return $items;
    }

    /** @return array<string, mixed>|null */
    public function find(string $typeHandle, string $idOrSlug): ?array
    {
        if ($this->useDatabase()) {
            return $this->findInDatabase($typeHandle, $idOrSlug);
        }

        foreach ($this->list($typeHandle, ['status' => 'all']) as $entry) {
            if (($entry['id'] ?? null) === $idOrSlug || ($entry['slug'] ?? null) === $idOrSlug) {
                return $entry;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(string $typeHandle, array $payload): array
    {
        if ($this->useDatabase()) {
            return $this->createInDatabase($typeHandle, $payload);
        }

        $type = $this->contentType($typeHandle);
        $normalized = (new ContentEntryValidator($type))->normalizeForCreate($payload);
        $items = $this->list($typeHandle, ['status' => 'all']);
        $slug = $this->uniqueSlug($items, $normalized['slug'], $normalized['locale']);
        $now = $this->now();
        $translationGroup = (string) ($payload['translation_group'] ?? Uuid::v4());

        $entry = [
            'id' => Uuid::v4(),
            'type' => $type['handle'],
            'title' => $normalized['title'],
            'slug' => $slug,
            'status' => $normalized['status'],
            'locale' => $normalized['locale'],
            'translation_group' => $translationGroup,
            'data' => $normalized['data'],
            'revision' => 1,
            'revisions' => [],
            'storage' => 'file',
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => $normalized['status'] === 'published' ? $now : null,
            'deleted_at' => null,
        ];
        $entry['revisions'][] = $this->revisionSnapshot($entry, 'created');

        $items[] = $entry;
        $this->persist($typeHandle, $items);
        $this->audit->record('content.entry.created', null, ['type' => $typeHandle, 'id' => $entry['id'], 'status' => $entry['status'], 'storage' => 'file']);
        return $entry;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    public function update(string $typeHandle, string $idOrSlug, array $payload): ?array
    {
        if ($this->useDatabase()) {
            return $this->updateInDatabase($typeHandle, $idOrSlug, $payload, 'updated');
        }

        $type = $this->contentType($typeHandle);
        $items = $this->list($typeHandle, ['status' => 'all']);
        foreach ($items as $index => $entry) {
            if (($entry['id'] ?? null) !== $idOrSlug && ($entry['slug'] ?? null) !== $idOrSlug) {
                continue;
            }

            $normalized = (new ContentEntryValidator($type))->normalizeForUpdate($payload, $entry);
            $slug = $this->uniqueSlug($items, $normalized['slug'], $normalized['locale'], (string) $entry['id']);
            $nextRevision = ((int) ($entry['revision'] ?? 1)) + 1;
            $now = $this->now();

            $updated = array_merge($entry, [
                'title' => $normalized['title'],
                'slug' => $slug,
                'status' => $normalized['status'],
                'locale' => $normalized['locale'],
                'translation_group' => (string) ($payload['translation_group'] ?? ($entry['translation_group'] ?? $entry['id'])),
                'data' => $normalized['data'],
                'revision' => $nextRevision,
                'storage' => 'file',
                'updated_at' => $now,
                'published_at' => $normalized['status'] === 'published' ? (($entry['published_at'] ?? null) ?: $now) : ($entry['published_at'] ?? null),
                'deleted_at' => $normalized['status'] === 'archived' ? (($entry['deleted_at'] ?? null) ?: $now) : ($entry['deleted_at'] ?? null),
            ]);

            $updated['revisions'] = $this->appendRevision(is_array($entry['revisions'] ?? null) ? $entry['revisions'] : [], $this->revisionSnapshot($updated, 'updated'));
            $items[$index] = $updated;
            $this->persist($typeHandle, $items);
            $this->audit->record('content.entry.updated', null, ['type' => $typeHandle, 'id' => $updated['id'], 'revision' => $updated['revision'], 'storage' => 'file']);
            return $updated;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function publish(string $typeHandle, string $idOrSlug): ?array
    {
        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return null;
        }
        $entry['status'] = 'published';
        $updated = $this->update($typeHandle, (string) $entry['id'], $entry);
        if ($updated !== null) {
            $this->audit->record('content.entry.published', null, ['type' => $typeHandle, 'id' => $updated['id'], 'storage' => $updated['storage'] ?? $this->storage()]);
        }
        return $updated;
    }

    /** @return array<string, mixed>|null */
    public function unpublish(string $typeHandle, string $idOrSlug): ?array
    {
        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return null;
        }
        $entry['status'] = 'draft';
        $updated = $this->update($typeHandle, (string) $entry['id'], $entry);
        if ($updated !== null) {
            $this->audit->record('content.entry.unpublished', null, ['type' => $typeHandle, 'id' => $updated['id'], 'storage' => $updated['storage'] ?? $this->storage()]);
        }
        return $updated;
    }

    /** @return array<string, mixed>|null */
    public function archive(string $typeHandle, string $idOrSlug): ?array
    {
        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return null;
        }
        $entry['status'] = 'archived';
        $updated = $this->update($typeHandle, (string) $entry['id'], $entry);
        if ($updated !== null) {
            $this->audit->record('content.entry.archived', null, ['type' => $typeHandle, 'id' => $updated['id'], 'storage' => $updated['storage'] ?? $this->storage()]);
        }
        return $updated;
    }

    public function delete(string $typeHandle, string $idOrSlug): bool
    {
        if ($this->useDatabase()) {
            return $this->deleteFromDatabase($typeHandle, $idOrSlug);
        }

        $items = $this->list($typeHandle, ['status' => 'all']);
        $before = count($items);
        $deletedId = null;
        $items = array_values(array_filter($items, static function (array $entry) use ($idOrSlug, &$deletedId): bool {
            $match = ($entry['id'] ?? null) === $idOrSlug || ($entry['slug'] ?? null) === $idOrSlug;
            if ($match) {
                $deletedId = (string) ($entry['id'] ?? $idOrSlug);
            }
            return !$match;
        }));

        if (count($items) === $before) {
            return false;
        }

        $this->persist($typeHandle, $items);
        $this->audit->record('content.entry.deleted', null, ['type' => $typeHandle, 'id' => $deletedId, 'storage' => 'file']);
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function revisions(string $typeHandle, string $idOrSlug): array
    {
        if ($this->useDatabase()) {
            return $this->revisionsFromDatabase($typeHandle, $idOrSlug);
        }

        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return [];
        }
        $revisions = $entry['revisions'] ?? [];
        return is_array($revisions) ? array_reverse(array_values($revisions)) : [];
    }

    /** @return array<string, mixed>|null */
    public function restoreRevision(string $typeHandle, string $idOrSlug, int $revision): ?array
    {
        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return null;
        }

        $snapshot = null;
        foreach ($this->revisions($typeHandle, $idOrSlug) as $item) {
            if (is_array($item) && (int) ($item['revision'] ?? 0) === $revision) {
                $snapshot = $item;
                break;
            }
        }
        if ($snapshot === null) {
            throw new \InvalidArgumentException('Ревизия не найдена.');
        }

        $payload = [
            'title' => $snapshot['title'] ?? $entry['title'],
            'slug' => $snapshot['slug'] ?? $entry['slug'],
            'status' => 'draft',
            'locale' => $snapshot['locale'] ?? $entry['locale'],
            'translation_group' => $entry['translation_group'] ?? $entry['id'],
            'data' => is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [],
        ];

        $restored = $this->useDatabase()
            ? $this->updateInDatabase($typeHandle, (string) $entry['id'], $payload, 'revision_restored')
            : $this->update($typeHandle, (string) $entry['id'], $payload);

        if ($restored !== null) {
            $this->audit->record('content.entry.revision_restored', null, ['type' => $typeHandle, 'id' => $restored['id'], 'from_revision' => $revision, 'storage' => $restored['storage'] ?? $this->storage()]);
        }
        return $restored;
    }

    /** @return list<array<string, mixed>> */
    public function localizations(string $typeHandle, string $idOrSlug): array
    {
        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return [];
        }

        $group = (string) ($entry['translation_group'] ?? $entry['id']);
        return array_values(array_filter($this->list($typeHandle, ['status' => 'all']), static fn (array $item): bool => (string) ($item['translation_group'] ?? $item['id'] ?? '') === $group));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    public function createLocalization(string $typeHandle, string $idOrSlug, array $payload): ?array
    {
        $entry = $this->find($typeHandle, $idOrSlug);
        if ($entry === null) {
            return null;
        }

        $payload['translation_group'] = (string) ($entry['translation_group'] ?? $entry['id']);
        $payload['status'] = $payload['status'] ?? 'draft';
        $created = $this->create($typeHandle, $payload);
        $this->audit->record('content.entry.localization_created', null, ['type' => $typeHandle, 'source_id' => $entry['id'], 'id' => $created['id'], 'locale' => $created['locale'], 'storage' => $created['storage'] ?? $this->storage()]);
        return $created;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        if ($this->useDatabase()) {
            try {
                $pdo = $this->pdo();
                $stmt = $pdo->query("SELECT COUNT(*) AS entries, SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts, SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS archived FROM ce_content_entries WHERE deleted_at IS NULL");
                $row = $stmt === false ? [] : ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
                $revisions = (int) ($pdo->query('SELECT COUNT(*) FROM ce_content_revisions')->fetchColumn() ?: 0);
                return [
                    'entries' => (int) ($row['entries'] ?? 0),
                    'published' => (int) ($row['published'] ?? 0),
                    'drafts' => (int) ($row['drafts'] ?? 0),
                    'archived' => (int) ($row['archived'] ?? 0),
                    'revisions' => $revisions,
                ];
            } catch (Throwable) {
                return ['entries' => 0, 'published' => 0, 'drafts' => 0, 'archived' => 0, 'revisions' => 0];
            }
        }

        $result = ['entries' => 0, 'published' => 0, 'drafts' => 0, 'archived' => 0, 'revisions' => 0];
        foreach ($this->types->all() as $type) {
            $handle = (string) ($type['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            foreach ($this->list($handle, ['status' => 'all']) as $entry) {
                $result['entries']++;
                if (($entry['status'] ?? null) === 'published') {
                    $result['published']++;
                }
                if (($entry['status'] ?? null) === 'draft') {
                    $result['drafts']++;
                }
                if (($entry['status'] ?? null) === 'archived') {
                    $result['archived']++;
                }
                $result['revisions'] += is_array($entry['revisions'] ?? null) ? count($entry['revisions']) : 0;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function contentType(string $typeHandle): array
    {
        $type = $this->types->find($typeHandle);
        if ($type === null) {
            throw new \InvalidArgumentException('Тип контента не найден: ' . $typeHandle);
        }
        return $type;
    }

    /** @return array<string, mixed> */
    private function read(string $typeHandle): array
    {
        return (new JsonFile($this->path($typeHandle)))->readObject(['version' => 1, 'entries' => []]);
    }

    /** @param list<array<string, mixed>> $items */
    private function persist(string $typeHandle, array $items): void
    {
        (new JsonFile($this->path($typeHandle)))->writeObject([
            'version' => 1,
            'type' => $typeHandle,
            'updated_at' => $this->now(),
            'entries' => array_values($items),
        ]);
    }

    private function path(string $typeHandle): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $typeHandle)) {
            throw new \InvalidArgumentException('Некорректный handle типа контента.');
        }
        return $this->rootPath . '/storage/app/content/' . $typeHandle . '.json';
    }

    /** @param list<array<string, mixed>> $items */
    private function uniqueSlug(array $items, string $baseSlug, string $locale, ?string $ignoreId = null): string
    {
        $slug = $baseSlug;
        $i = 2;
        while ($this->slugExists($items, $slug, $locale, $ignoreId)) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /** @param list<array<string, mixed>> $items */
    private function slugExists(array $items, string $slug, string $locale, ?string $ignoreId): bool
    {
        foreach ($items as $item) {
            if ($ignoreId !== null && ($item['id'] ?? null) === $ignoreId) {
                continue;
            }
            if (($item['slug'] ?? null) === $slug && ($item['locale'] ?? null) === $locale && ($item['deleted_at'] ?? null) === null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function revisionSnapshot(array $entry, string $reason): array
    {
        return [
            'revision' => (int) ($entry['revision'] ?? 1),
            'reason' => $reason,
            'title' => $entry['title'] ?? '',
            'slug' => $entry['slug'] ?? '',
            'status' => $entry['status'] ?? 'draft',
            'locale' => $entry['locale'] ?? 'ru',
            'translation_group' => $entry['translation_group'] ?? $entry['id'] ?? null,
            'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
            'created_at' => $this->now(),
        ];
    }

    /** @param list<array<string, mixed>> $revisions @param array<string, mixed> $snapshot @return list<array<string, mixed>> */
    private function appendRevision(array $revisions, array $snapshot): array
    {
        $revisions[] = $snapshot;
        return array_slice($revisions, -50);
    }

    private function useDatabase(): bool
    {
        if ($this->databaseReady !== null) {
            return $this->databaseReady;
        }

        $mode = strtolower(trim((string) (getenv('CONTENT_STORAGE') ?: 'auto')));
        if ($mode === 'file') {
            return $this->databaseReady = false;
        }

        try {
            $this->databaseReady = $this->database()->contentCoreReady();
        } catch (Throwable $e) {
            if ($mode === 'database') {
                throw new \RuntimeException('CONTENT_STORAGE=database, но БД content core недоступна: ' . $e->getMessage(), 0, $e);
            }
            $this->databaseReady = false;
        }

        if ($mode === 'database' && $this->databaseReady !== true) {
            throw new \RuntimeException('CONTENT_STORAGE=database, но таблицы content core не готовы. Выполните миграции.');
        }

        return $this->databaseReady;
    }

    private function database(): DatabaseManager
    {
        if (!$this->database instanceof DatabaseManager) {
            $this->database = new DatabaseManager(new ConfigRepository($this->rootPath));
        }
        return $this->database;
    }

    private function pdo(): PDO
    {
        return $this->database()->connection();
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function listFromDatabase(string $typeHandle, array $filters): array
    {
        $type = $this->contentType($typeHandle);
        $where = ['ct.handle = :handle', 'e.deleted_at IS NULL'];
        $params = ['handle' => $type['handle']];

        $status = (string) ($filters['status'] ?? 'all');
        $locale = (string) ($filters['locale'] ?? '');
        $query = trim((string) ($filters['q'] ?? ''));
        $publishedOnly = filter_var($filters['published_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($publishedOnly) {
            $status = 'published';
        }
        if ($status !== 'all' && $status !== '') {
            $where[] = 'e.status = :status';
            $params['status'] = $status;
        }
        if ($locale !== '') {
            $where[] = 'e.locale = :locale';
            $params['locale'] = $locale;
        }
        if ($query !== '') {
            $where[] = '(LOWER(e.title) LIKE :q OR LOWER(e.slug) LIKE :q OR LOWER(CAST(e.data AS CHAR)) LIKE :q)';
            if ($this->database()->driver() === 'pgsql') {
                $where[count($where) - 1] = '(LOWER(e.title) LIKE :q OR LOWER(e.slug) LIKE :q OR LOWER(e.data::text) LIKE :q)';
            }
            $params['q'] = '%' . self::lower($query) . '%';
        }

        $sql = 'SELECT e.*, ct.handle AS type_handle FROM ce_content_entries e JOIN ce_content_types ct ON ct.id = e.content_type_id WHERE ' . implode(' AND ', $where) . ' ORDER BY e.updated_at DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->mapEntryRow($row, $type), $rows ?: []);
    }

    /** @return array<string, mixed>|null */
    private function findInDatabase(string $typeHandle, string $idOrSlug): ?array
    {
        $type = $this->contentType($typeHandle);
        $stmt = $this->pdo()->prepare('SELECT e.*, ct.handle AS type_handle FROM ce_content_entries e JOIN ce_content_types ct ON ct.id = e.content_type_id WHERE ct.handle = :handle AND e.deleted_at IS NULL AND (e.id = :id OR e.slug = :slug) LIMIT 1');
        $stmt->execute(['handle' => $type['handle'], 'id' => $idOrSlug, 'slug' => $idOrSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->mapEntryRow($row, $type) : null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function createInDatabase(string $typeHandle, array $payload): array
    {
        $type = $this->contentType($typeHandle);
        $normalized = (new ContentEntryValidator($type))->normalizeForCreate($payload);
        $now = $this->nowForDatabase();
        $id = Uuid::v4();
        $translationGroup = (string) ($payload['translation_group'] ?? Uuid::v4());

        $slug = $this->uniqueSlugInDatabase((string) $type['id'], $normalized['slug'], $normalized['locale']);
        $publishedAt = $normalized['status'] === 'published' ? $now : null;

        $this->database()->transaction(function (PDO $pdo) use ($type, $normalized, $id, $translationGroup, $slug, $now, $publishedAt): void {
            $stmt = $pdo->prepare('INSERT INTO ce_content_entries (id, content_type_id, title, slug, locale, translation_group, status, data, revision_number, published_at, created_at, updated_at) VALUES (:id, :content_type_id, :title, :slug, :locale, :translation_group, :status, :data, :revision_number, :published_at, :created_at, :updated_at)');
            $stmt->execute([
                'id' => $id,
                'content_type_id' => $type['id'],
                'title' => $normalized['title'],
                'slug' => $slug,
                'locale' => $normalized['locale'],
                'translation_group' => $translationGroup,
                'status' => $normalized['status'],
                'data' => $this->jsonEncode($normalized['data']),
                'revision_number' => 1,
                'published_at' => $publishedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $entry = [
                'id' => $id,
                'type' => $type['handle'],
                'title' => $normalized['title'],
                'slug' => $slug,
                'status' => $normalized['status'],
                'locale' => $normalized['locale'],
                'translation_group' => $translationGroup,
                'data' => $normalized['data'],
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'published_at' => $publishedAt,
                'deleted_at' => null,
            ];
            $this->insertRevision($pdo, $id, 1, $this->revisionSnapshot($entry, 'created'));
        });

        $created = $this->findInDatabase((string) $type['handle'], $id) ?? throw new \RuntimeException('Запись создана, но не найдена после сохранения.');
        $this->audit->record('content.entry.created', null, ['type' => $typeHandle, 'id' => $id, 'status' => $created['status'], 'storage' => 'database']);
        return $created;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    private function updateInDatabase(string $typeHandle, string $idOrSlug, array $payload, string $reason): ?array
    {
        $type = $this->contentType($typeHandle);
        $existing = $this->findInDatabase($typeHandle, $idOrSlug);
        if ($existing === null) {
            return null;
        }

        $normalized = (new ContentEntryValidator($type))->normalizeForUpdate($payload, $existing);
        $slug = $this->uniqueSlugInDatabase((string) $type['id'], $normalized['slug'], $normalized['locale'], (string) $existing['id']);
        $revision = ((int) ($existing['revision'] ?? 1)) + 1;
        $now = $this->nowForDatabase();
        $publishedAt = $normalized['status'] === 'published' ? (($existing['published_at'] ?? null) ?: $now) : ($existing['published_at'] ?? null);
        $deletedAt = $normalized['status'] === 'archived' ? (($existing['deleted_at'] ?? null) ?: $now) : ($existing['deleted_at'] ?? null);
        $translationGroup = (string) ($payload['translation_group'] ?? ($existing['translation_group'] ?? $existing['id']));

        $this->database()->transaction(function (PDO $pdo) use ($existing, $normalized, $slug, $translationGroup, $revision, $now, $publishedAt, $deletedAt, $reason): void {
            $stmt = $pdo->prepare('UPDATE ce_content_entries SET title = :title, slug = :slug, locale = :locale, translation_group = :translation_group, status = :status, data = :data, revision_number = :revision_number, published_at = :published_at, deleted_at = :deleted_at, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'id' => $existing['id'],
                'title' => $normalized['title'],
                'slug' => $slug,
                'locale' => $normalized['locale'],
                'translation_group' => $translationGroup,
                'status' => $normalized['status'],
                'data' => $this->jsonEncode($normalized['data']),
                'revision_number' => $revision,
                'published_at' => $publishedAt,
                'deleted_at' => $deletedAt,
                'updated_at' => $now,
            ]);
            $entry = array_merge($existing, [
                'title' => $normalized['title'],
                'slug' => $slug,
                'locale' => $normalized['locale'],
                'translation_group' => $translationGroup,
                'status' => $normalized['status'],
                'data' => $normalized['data'],
                'revision' => $revision,
                'published_at' => $publishedAt,
                'deleted_at' => $deletedAt,
                'updated_at' => $now,
            ]);
            $this->insertRevision($pdo, (string) $existing['id'], $revision, $this->revisionSnapshot($entry, $reason));
        });

        $updated = $this->findInDatabase((string) $type['handle'], (string) $existing['id']);
        if ($updated !== null) {
            $this->audit->record('content.entry.updated', null, ['type' => $typeHandle, 'id' => $updated['id'], 'revision' => $updated['revision'], 'storage' => 'database']);
        }
        return $updated;
    }

    private function deleteFromDatabase(string $typeHandle, string $idOrSlug): bool
    {
        $entry = $this->findInDatabase($typeHandle, $idOrSlug);
        if ($entry === null) {
            return false;
        }

        $stmt = $this->pdo()->prepare('UPDATE ce_content_entries SET deleted_at = :deleted_at, status = :status, updated_at = :updated_at WHERE id = :id');
        $now = $this->nowForDatabase();
        $stmt->execute(['id' => $entry['id'], 'status' => 'archived', 'deleted_at' => $now, 'updated_at' => $now]);
        $this->audit->record('content.entry.deleted', null, ['type' => $typeHandle, 'id' => $entry['id'], 'storage' => 'database']);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    private function revisionsFromDatabase(string $typeHandle, string $idOrSlug): array
    {
        $entry = $this->findInDatabase($typeHandle, $idOrSlug);
        if ($entry === null) {
            return [];
        }

        $stmt = $this->pdo()->prepare('SELECT revision_number, data, created_at FROM ce_content_revisions WHERE entry_id = :entry_id ORDER BY revision_number DESC');
        $stmt->execute(['entry_id' => $entry['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $snapshot = $this->jsonDecode($row['data'] ?? '{}');
            if (is_array($snapshot)) {
                $snapshot['revision'] = (int) ($snapshot['revision'] ?? $row['revision_number']);
                $snapshot['created_at'] = (string) ($snapshot['created_at'] ?? $row['created_at'] ?? '');
                $items[] = $snapshot;
            }
        }
        return $items;
    }

    /** @param array<string, mixed> $snapshot */
    private function insertRevision(PDO $pdo, string $entryId, int $revision, array $snapshot): void
    {
        $stmt = $pdo->prepare('INSERT INTO ce_content_revisions (id, entry_id, revision_number, data, created_at) VALUES (:id, :entry_id, :revision_number, :data, :created_at)');
        $stmt->execute([
            'id' => Uuid::v4(),
            'entry_id' => $entryId,
            'revision_number' => $revision,
            'data' => $this->jsonEncode($snapshot),
            'created_at' => $this->nowForDatabase(),
        ]);
    }

    private function uniqueSlugInDatabase(string $contentTypeId, string $baseSlug, string $locale, ?string $ignoreId = null): string
    {
        $slug = $baseSlug;
        $i = 2;
        while ($this->slugExistsInDatabase($contentTypeId, $slug, $locale, $ignoreId)) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private function slugExistsInDatabase(string $contentTypeId, string $slug, string $locale, ?string $ignoreId): bool
    {
        $sql = 'SELECT COUNT(*) FROM ce_content_entries WHERE content_type_id = :content_type_id AND slug = :slug AND locale = :locale AND deleted_at IS NULL';
        $params = ['content_type_id' => $contentTypeId, 'slug' => $slug, 'locale' => $locale];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $type @return array<string, mixed> */
    private function mapEntryRow(array $row, array $type): array
    {
        return [
            'id' => (string) $row['id'],
            'type' => (string) ($row['type_handle'] ?? $type['handle']),
            'title' => (string) ($row['title'] ?? 'Без названия'),
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'locale' => (string) $row['locale'],
            'translation_group' => (string) ($row['translation_group'] ?? $row['id']),
            'data' => $this->jsonDecode($row['data'] ?? '{}'),
            'revision' => (int) ($row['revision_number'] ?? 1),
            'storage' => 'database',
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
            'deleted_at' => $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
        ];
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    private function nowForDatabase(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /** @param mixed $value */
    private function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return mixed */
    private function jsonDecode(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return $decoded === null ? [] : $decoded;
    }
}
