<?php

declare(strict_types=1);

namespace CajeerEngine\Cms;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;
use PDO;
use Throwable;

final class NavigationRepository
{
    private JsonFile $file;
    private ?DatabaseManager $database = null;
    private ?bool $databaseReady = null;

    public function __construct(private readonly string $rootPath, ?DatabaseManager $database = null)
    {
        $this->database = $database;
        $this->file = new JsonFile($this->rootPath . '/storage/app/cms/navigation.json');
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        if ($this->useDatabase()) {
            return $this->allDatabase();
        }

        $data = $this->file->readObject(['menus' => []]);
        $items = is_array($data['menus'] ?? null) ? $data['menus'] : [];
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($a['handle'] ?? ''), (string) ($b['handle'] ?? '')));
        return array_values($items);
    }

    /** @return array<string,mixed>|null */
    public function find(string $handle): ?array
    {
        $handle = $this->handle($handle, 'primary');
        foreach ($this->all() as $menu) {
            if (($menu['handle'] ?? null) === $handle || ($menu['id'] ?? null) === $handle) {
                return $menu;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    public function save(string $handle, string $name, array $items): array
    {
        $handle = $this->handle($handle, 'primary');
        $name = trim($name) !== '' ? trim($name) : ucfirst($handle);
        $items = $this->normalizeItems($items);

        if ($this->useDatabase()) {
            return $this->saveDatabase($handle, $name, $items);
        }

        $menus = $this->all();
        $now = $this->now();
        $found = false;
        foreach ($menus as $index => $menu) {
            if (($menu['handle'] ?? null) !== $handle) {
                continue;
            }
            $menus[$index] = array_merge($menu, [
                'name' => $name,
                'items' => $items,
                'storage' => 'file',
                'updated_at' => $now,
            ]);
            $found = true;
            break;
        }

        if (!$found) {
            $menus[] = [
                'id' => Uuid::v4(),
                'handle' => $handle,
                'name' => $name,
                'items' => $items,
                'storage' => 'file',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->persist($menus);
        return $this->find($handle) ?? throw new \RuntimeException('Меню сохранено, но не найдено.');
    }

    public function delete(string $handle): bool
    {
        $handle = $this->handle($handle, 'primary');
        if ($this->useDatabase()) {
            $menu = $this->find($handle);
            if ($menu === null) {
                return false;
            }
            $stmt = $this->pdo()->prepare('DELETE FROM ce_cms_navigation_menus WHERE id = :id');
            $stmt->execute(['id' => $menu['id']]);
            return $stmt->rowCount() > 0;
        }

        $menus = $this->all();
        $before = count($menus);
        $menus = array_values(array_filter($menus, static fn (array $menu): bool => ($menu['handle'] ?? null) !== $handle));
        if (count($menus) === $before) {
            return false;
        }
        $this->persist($menus);
        return true;
    }

    /** @return array<string,mixed> */
    public function primary(): array
    {
        return $this->find('primary') ?? [
            'id' => 'primary',
            'handle' => 'primary',
            'name' => 'Основное меню',
            'items' => [
                ['id' => 'home', 'label' => 'Главная', 'url' => '/', 'target' => '_self', 'children' => []],
            ],
            'storage' => 'default',
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function allDatabase(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM ce_cms_navigation_menus ORDER BY handle ASC');
        $menus = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $result = [];
        foreach ($menus as $menu) {
            $result[] = $this->mapMenuRow($menu, $this->itemsDatabase((string) $menu['id']));
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function itemsDatabase(string $menuId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ce_cms_navigation_items WHERE menu_id = :menu_id ORDER BY sort_order ASC, label ASC');
        $stmt->execute(['menu_id' => $menuId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (string) $row['id'],
                'label' => (string) $row['label'],
                'url' => (string) $row['url'],
                'target' => (string) ($row['target'] ?: '_self'),
                'entry_type' => $row['entry_type'] !== null ? (string) $row['entry_type'] : null,
                'entry_id' => $row['entry_id'] !== null ? (string) $row['entry_id'] : null,
                'data' => $this->jsonDecode($row['data'] ?? '{}'),
                'children' => [],
            ];
        }
        return $items;
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function saveDatabase(string $handle, string $name, array $items): array
    {
        return $this->database()->transaction(function (PDO $pdo) use ($handle, $name, $items): array {
            $existing = null;
            $stmt = $pdo->prepare('SELECT * FROM ce_cms_navigation_menus WHERE handle = :handle LIMIT 1');
            $stmt->execute(['handle' => $handle]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $existing = $row;
            }

            $menuId = is_array($existing) ? (string) $existing['id'] : Uuid::v4();
            $now = $this->nowForDatabase();

            if (is_array($existing)) {
                $stmt = $pdo->prepare('UPDATE ce_cms_navigation_menus SET name = :name, updated_at = :updated_at WHERE id = :id');
                $stmt->execute(['id' => $menuId, 'name' => $name, 'updated_at' => $now]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO ce_cms_navigation_menus (id, handle, name, data, created_at, updated_at) VALUES (:id, :handle, :name, :data, :created_at, :updated_at)');
                $stmt->execute(['id' => $menuId, 'handle' => $handle, 'name' => $name, 'data' => '{}', 'created_at' => $now, 'updated_at' => $now]);
            }

            $pdo->prepare('DELETE FROM ce_cms_navigation_items WHERE menu_id = :menu_id')->execute(['menu_id' => $menuId]);
            foreach ($items as $index => $item) {
                $stmt = $pdo->prepare('INSERT INTO ce_cms_navigation_items (id, menu_id, parent_id, label, url, entry_type, entry_id, target, sort_order, data, created_at, updated_at) VALUES (:id, :menu_id, :parent_id, :label, :url, :entry_type, :entry_id, :target, :sort_order, :data, :created_at, :updated_at)');
                $stmt->execute([
                    'id' => (string) ($item['id'] ?? Uuid::v4()),
                    'menu_id' => $menuId,
                    'parent_id' => null,
                    'label' => (string) $item['label'],
                    'url' => (string) $item['url'],
                    'entry_type' => $item['entry_type'] ?? null,
                    'entry_id' => $item['entry_id'] ?? null,
                    'target' => (string) ($item['target'] ?? '_self'),
                    'sort_order' => $index,
                    'data' => $this->jsonEncode($item['data'] ?? []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $this->find($handle) ?? [
                'id' => $menuId,
                'handle' => $handle,
                'name' => $name,
                'items' => $items,
                'storage' => 'database',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function mapMenuRow(array $row, array $items): array
    {
        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'name' => (string) $row['name'],
            'items' => $items,
            'data' => $this->jsonDecode($row['data'] ?? '{}'),
            'storage' => 'database',
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            if (!str_starts_with($url, '/') && filter_var($url, FILTER_VALIDATE_URL) === false) {
                $url = '/' . ltrim($url, '/');
            }
            $target = (string) ($item['target'] ?? '_self');
            $normalized[] = [
                'id' => trim((string) ($item['id'] ?? '')) ?: Uuid::v4(),
                'label' => $this->short($label, 120),
                'url' => $this->short($url, 500),
                'target' => in_array($target, ['_self', '_blank'], true) ? $target : '_self',
                'entry_type' => isset($item['entry_type']) && is_scalar($item['entry_type']) ? (string) $item['entry_type'] : null,
                'entry_id' => isset($item['entry_id']) && is_scalar($item['entry_id']) ? (string) $item['entry_id'] : null,
                'data' => is_array($item['data'] ?? null) ? $item['data'] : [],
                'children' => [],
            ];
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $menus */
    private function persist(array $menus): void
    {
        $this->file->writeObject([
            'version' => 1,
            'updated_at' => $this->now(),
            'menus' => array_values($menus),
        ]);
    }

    private function useDatabase(): bool
    {
        if ($this->databaseReady !== null) {
            return $this->databaseReady;
        }
        $mode = strtolower(trim((string) (getenv('CMS_STORAGE') ?: 'auto')));
        if ($mode === 'file') {
            return $this->databaseReady = false;
        }
        try {
            $this->databaseReady = $this->database()->tableExists('ce_cms_navigation_menus') && $this->database()->tableExists('ce_cms_navigation_items');
        } catch (Throwable $e) {
            if ($mode === 'database') {
                throw new \RuntimeException('CMS_STORAGE=database, но CMS navigation tables недоступны: ' . $e->getMessage(), 0, $e);
            }
            $this->databaseReady = false;
        }
        if ($mode === 'database' && $this->databaseReady !== true) {
            throw new \RuntimeException('CMS_STORAGE=database, но таблицы navigation не найдены. Выполните миграции.');
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

    private function handle(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^[a-z][a-z0-9_]{1,99}$/', $value) ? $value : $fallback;
    }

    private function short(string $value, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    private function nowForDatabase(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
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
}
