<?php

declare(strict_types=1);

namespace CajeerEngine\Database\Repository;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Support\Uuid;

final readonly class RoleRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->database->connection()->query('SELECT id, handle, name, permissions, created_at, updated_at FROM ce_roles ORDER BY handle ASC')->fetchAll();
        return array_map(fn (array $row): array => $this->normalize($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $idOrHandle): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT id, handle, name, permissions, created_at, updated_at FROM ce_roles WHERE id = :id_value OR handle = :handle_value LIMIT 1');
        $stmt->execute(['id_value' => $idOrHandle, 'handle_value' => $idOrHandle]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function seedDefaults(): int
    {
        $defaults = [
            'admin' => ['Администратор', ['*']],
            'developer' => ['Разработчик', ['system:read', 'system:write', 'extensions:read', 'extensions:write', 'settings:read', 'settings:write', 'users:read', 'users:write', 'imports:read', 'imports:write', 'updates:read', 'updates:write', 'release:build']],
            'editor' => ['Редактор', ['content:read', 'content:write', 'media:read', 'media:write']],
            'viewer' => ['Наблюдатель', ['content:read', 'media:read', 'system:read']],
        ];

        $count = 0;
        foreach ($defaults as $handle => [$name, $permissions]) {
            $this->upsert($handle, $name, $permissions);
            $count++;
        }
        return $count;
    }

    /** @param list<string> $permissions */
    public function upsert(string $handle, string $name, array $permissions): string
    {
        $this->assertHandle($handle);
        $id = $this->findIdByHandle($handle) ?: Uuid::v4();
        $permissions = $this->normalizePermissions($permissions);
        $json = json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $driver = $this->database->driver();
        if ($driver === 'pgsql') {
            $sql = "INSERT INTO ce_roles (id, handle, name, permissions, created_at, updated_at)
               VALUES (:id, :handle, :name, CAST(:permissions AS jsonb), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
               ON CONFLICT (handle) DO UPDATE SET name = EXCLUDED.name, permissions = EXCLUDED.permissions, updated_at = CURRENT_TIMESTAMP";
        } elseif ($driver === 'sqlite') {
            $sql = "INSERT INTO ce_roles (id, handle, name, permissions, created_at, updated_at)
               VALUES (:id, :handle, :name, :permissions, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
               ON CONFLICT(handle) DO UPDATE SET name = excluded.name, permissions = excluded.permissions, updated_at = CURRENT_TIMESTAMP";
        } else {
            $sql = "INSERT INTO ce_roles (id, handle, name, permissions, created_at, updated_at)
               VALUES (:id, :handle, :name, :permissions, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
               ON DUPLICATE KEY UPDATE name = VALUES(name), permissions = VALUES(permissions), updated_at = CURRENT_TIMESTAMP";
        }
        $stmt = $this->database->connection()->prepare($sql);
        $stmt->execute(['id' => $id, 'handle' => $handle, 'name' => $name, 'permissions' => $json]);
        return $id;
    }

    public function delete(string $idOrHandle): bool
    {
        $role = $this->find($idOrHandle);
        if ($role === null) {
            return false;
        }
        if (in_array($role['handle'], ['admin', 'developer', 'editor', 'viewer'], true)) {
            throw new \InvalidArgumentException('Системную роль нельзя удалить.');
        }
        $stmt = $this->database->connection()->prepare('DELETE FROM ce_roles WHERE id = :id');
        $stmt->execute(['id' => $role['id']]);
        return $stmt->rowCount() > 0;
    }

    public function findIdByHandle(string $handle): ?string
    {
        $stmt = $this->database->connection()->prepare('SELECT id FROM ce_roles WHERE handle = :handle');
        $stmt->execute(['handle' => $handle]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /** @return list<string> */
    public function permissionsForUser(string $userId): array
    {
        $stmt = $this->database->connection()->prepare('SELECT r.permissions FROM ce_roles r INNER JOIN ce_user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $permissions = [];
        foreach ($stmt->fetchAll() as $row) {
            foreach ($this->decodeList((string) $row['permissions']) as $permission) {
                $permissions[] = $permission;
            }
        }
        $permissions = array_values(array_unique($permissions));
        sort($permissions);
        return $permissions;
    }

    /** @param list<string> $permissions @return list<string> */
    public function normalizePermissions(array $permissions): array
    {
        if (in_array('*', $permissions, true)) {
            return ['*'];
        }
        $permissions = array_values(array_unique(array_filter(array_map('strval', $permissions), static fn (string $permission): bool => preg_match('/^[a-z0-9*_.:-]+$/', $permission) === 1)));
        sort($permissions);
        return $permissions;
    }

    /** @return array<string, mixed> */
    private function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'handle' => (string) $row['handle'],
            'name' => (string) $row['name'],
            'permissions' => $this->decodeList((string) $row['permissions']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function assertHandle(string $handle): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $handle)) {
            throw new \InvalidArgumentException('Некорректный handle роли.');
        }
    }
}
