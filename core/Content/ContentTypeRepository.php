<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;
use PDO;
use Throwable;

final class ContentTypeRepository
{
    private JsonFile $file;
    private ?DatabaseManager $database = null;
    private ?bool $databaseReady = null;

    public function __construct(private readonly string $rootPath, ?DatabaseManager $database = null)
    {
        $this->file = new JsonFile($this->rootPath . '/storage/app/content-types.json');
        $this->database = $database;
    }

    public function storage(): string
    {
        return $this->useDatabase() ? 'database' : 'file';
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->useDatabase()) {
            return $this->allFromDatabase();
        }

        $data = $this->file->readObject(['content_types' => []]);
        $items = $data['content_types'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['handle'], (string) $b['handle']));
        return array_values($items);
    }

    /** @return array<string, mixed>|null */
    public function find(string $handle): ?array
    {
        if ($this->useDatabase()) {
            return $this->findInDatabase($handle);
        }

        foreach ($this->all() as $item) {
            if (($item['handle'] ?? null) === $handle || ($item['id'] ?? null) === $handle) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(array $payload): array
    {
        $payload = ContentTypeValidator::normalize($payload);

        if ($this->find((string) $payload['handle']) !== null) {
            throw new \DomainException('Тип контента с таким handle уже существует.');
        }

        if ($this->useDatabase()) {
            return $this->createInDatabase($payload);
        }

        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $item = [
            'id' => Uuid::v4(),
            'handle' => $payload['handle'],
            'name' => $payload['name'],
            'fields' => $payload['fields'],
            'blocks' => $payload['blocks'],
            'localized' => $payload['localized'],
            'revisionable' => $payload['revisionable'],
            'storage' => 'file',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $items = $this->all();
        $items[] = $item;
        $this->persist($items);

        return $item;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(string $handle, array $payload): array
    {
        if ($this->useDatabase()) {
            return $this->updateInDatabase($handle, $payload);
        }

        $items = $this->all();
        foreach ($items as $index => $item) {
            if (($item['handle'] ?? null) !== $handle && ($item['id'] ?? null) !== $handle) {
                continue;
            }

            $merged = array_merge($item, $payload);
            $normalized = ContentTypeValidator::normalize($merged);
            $items[$index] = array_merge($item, $normalized, [
                'id' => $item['id'],
                'storage' => 'file',
                'created_at' => $item['created_at'],
                'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);

            $this->persist($items);
            return $items[$index];
        }

        throw new \RuntimeException('Тип контента не найден.');
    }

    public function delete(string $handle): bool
    {
        if ($this->useDatabase()) {
            return $this->deleteFromDatabase($handle);
        }

        $items = $this->all();
        $before = count($items);
        $items = array_values(array_filter($items, static fn (array $item): bool => ($item['handle'] ?? null) !== $handle && ($item['id'] ?? null) !== $handle));
        if (count($items) === $before) {
            return false;
        }

        $this->persist($items);
        return true;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return ['content_types' => count($this->all())];
    }

    /** @param list<array<string, mixed>> $items */
    private function persist(array $items): void
    {
        $this->file->writeObject([
            'version' => 1,
            'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'content_types' => array_values($items),
        ]);
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
            $database = $this->database();
            $this->databaseReady = $database->tableExists('ce_content_types');
        } catch (Throwable $e) {
            if ($mode === 'database') {
                throw new \RuntimeException('CONTENT_STORAGE=database, но БД content core недоступна: ' . $e->getMessage(), 0, $e);
            }
            $this->databaseReady = false;
        }

        if ($mode === 'database' && $this->databaseReady !== true) {
            throw new \RuntimeException('CONTENT_STORAGE=database, но таблица ce_content_types не найдена. Выполните миграции.');
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

    /** @return list<array<string, mixed>> */
    private function allFromDatabase(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM ce_content_types ORDER BY handle ASC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->mapDatabaseRow($row), $rows);
    }

    /** @return array<string, mixed>|null */
    private function findInDatabase(string $handle): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ce_content_types WHERE handle = :handle OR id = :id LIMIT 1');
        $stmt->execute(['handle' => $handle, 'id' => $handle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->mapDatabaseRow($row) : null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function createInDatabase(array $payload): array
    {
        $id = Uuid::v4();
        $now = $this->nowForDatabase();
        $stmt = $this->pdo()->prepare(
            'INSERT INTO ce_content_types (id, handle, name, fields, blocks, localized, revisionable, created_at, updated_at) VALUES (:id, :handle, :name, :fields, :blocks, :localized, :revisionable, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'handle' => $payload['handle'],
            'name' => $payload['name'],
            'fields' => $this->jsonEncode($payload['fields']),
            'blocks' => $this->jsonEncode($payload['blocks']),
            'localized' => $this->boolParam((bool) $payload['localized']),
            'revisionable' => $this->boolParam((bool) $payload['revisionable']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findInDatabase((string) $payload['handle']) ?? throw new \RuntimeException('Тип контента создан, но не найден после вставки.');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function updateInDatabase(string $handle, array $payload): array
    {
        $existing = $this->findInDatabase($handle);
        if ($existing === null) {
            throw new \RuntimeException('Тип контента не найден.');
        }

        $merged = array_merge($existing, $payload);
        $normalized = ContentTypeValidator::normalize($merged);
        $stmt = $this->pdo()->prepare(
            'UPDATE ce_content_types SET handle = :handle, name = :name, fields = :fields, blocks = :blocks, localized = :localized, revisionable = :revisionable, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $existing['id'],
            'handle' => $normalized['handle'],
            'name' => $normalized['name'],
            'fields' => $this->jsonEncode($normalized['fields']),
            'blocks' => $this->jsonEncode($normalized['blocks']),
            'localized' => $this->boolParam((bool) $normalized['localized']),
            'revisionable' => $this->boolParam((bool) $normalized['revisionable']),
            'updated_at' => $this->nowForDatabase(),
        ]);

        return $this->findInDatabase((string) $normalized['handle']) ?? throw new \RuntimeException('Тип контента обновлён, но не найден после сохранения.');
    }

    private function deleteFromDatabase(string $handle): bool
    {
        $existing = $this->findInDatabase($handle);
        if ($existing === null) {
            return false;
        }

        $stmt = $this->pdo()->prepare('DELETE FROM ce_content_types WHERE id = :id');
        $stmt->execute(['id' => $existing['id']]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapDatabaseRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'name' => (string) $row['name'],
            'fields' => $this->jsonDecode($row['fields'] ?? '[]'),
            'blocks' => $this->jsonDecode($row['blocks'] ?? '[]'),
            'localized' => $this->boolFromDatabase($row['localized'] ?? false),
            'revisionable' => $this->boolFromDatabase($row['revisionable'] ?? true),
            'storage' => 'database',
            'created_at' => $this->dateString($row['created_at'] ?? null),
            'updated_at' => $this->dateString($row['updated_at'] ?? null),
        ];
    }

    private function pdo(): PDO
    {
        return $this->database()->connection();
    }

    private function boolParam(bool $value): int
    {
        return $value ? 1 : 0;
    }

    private function boolFromDatabase(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 't', 'true', 'yes'], true);
        }
        return (bool) $value;
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

    /** @param mixed $value */
    private function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        return (string) $value;
    }

    private function nowForDatabase(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
