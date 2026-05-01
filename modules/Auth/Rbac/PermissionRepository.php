<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth\Rbac;

use Cajeer\Database\DatabaseManager;
use Throwable;

final class PermissionRepository
{
    private const DEFAULT_PERMISSIONS = [
        'admin.access' => 'Доступ в админку',
        'content.read' => 'Чтение контента',
        'content.write' => 'Запись контента',
        'content.delete' => 'Удаление контента',
        'marketplace.read' => 'Просмотр marketplace',
        'marketplace.install' => 'Установка пакетов',
        'settings.write' => 'Изменение настроек',
        'compatibility.run' => 'Запуск compatibility-инструментов',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    public function ensureDefaults(?int $adminUserId = null): void
    {
        try {
            $pdo = $this->database->connection();
            $pdo->exec("INSERT INTO cajeer_roles (slug, title) VALUES ('administrator', 'Администратор')");
        } catch (Throwable) {}

        foreach (self::DEFAULT_PERMISSIONS as $slug => $title) {
            try {
                $stmt = $this->database->connection()->prepare('INSERT INTO cajeer_permissions (slug, title) VALUES (:slug, :title)');
                $stmt->execute(['slug' => $slug, 'title' => $title]);
            } catch (Throwable) {}
        }

        try {
            $pdo = $this->database->connection();
            $pdo->exec("INSERT INTO cajeer_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM cajeer_roles r CROSS JOIN cajeer_permissions p WHERE r.slug = 'administrator'");
        } catch (Throwable) {}

        if ($adminUserId !== null) {
            try {
                $stmt = $this->database->connection()->prepare("INSERT INTO cajeer_user_roles (user_id, role_id) SELECT :user_id, id FROM cajeer_roles WHERE slug = 'administrator'");
                $stmt->execute(['user_id' => $adminUserId]);
            } catch (Throwable) {}
        }
    }

    public function userHas(int $userId, string $permission): bool
    {
        try {
            $stmt = $this->database->connection()->prepare(
                'SELECT 1 FROM cajeer_user_roles ur JOIN cajeer_role_permissions rp ON rp.role_id = ur.role_id JOIN cajeer_permissions p ON p.id = rp.permission_id WHERE ur.user_id = :user_id AND p.slug = :permission LIMIT 1'
            );
            $stmt->execute(['user_id' => $userId, 'permission' => $permission]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}
