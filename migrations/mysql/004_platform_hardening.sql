ALTER TABLE cajeer_content ADD COLUMN meta_title VARCHAR(500) NULL;
ALTER TABLE cajeer_content ADD COLUMN meta_description TEXT NULL;
ALTER TABLE cajeer_content ADD COLUMN canonical_url VARCHAR(500) NULL;
ALTER TABLE cajeer_content ADD COLUMN cover_image VARCHAR(500) NULL;
ALTER TABLE cajeer_content ADD COLUMN category_id BIGINT UNSIGNED NULL;
ALTER TABLE cajeer_content ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cajeer_content ADD COLUMN visibility VARCHAR(32) NOT NULL DEFAULT 'public';
ALTER TABLE cajeer_content ADD COLUMN revision_id BIGINT UNSIGNED NULL;
ALTER TABLE cajeer_content ADD INDEX idx_cajeer_content_status_pub (status, published_at);

CREATE TABLE IF NOT EXISTS cajeer_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cajeer_content_tags (
    content_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (content_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cajeer_content_categories (
    content_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (content_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cajeer_content_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id BIGINT UNSIGNED NOT NULL,
    snapshot_json JSON NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cajeer_content_revisions_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cajeer_roles (slug, title) VALUES ('administrator', 'Администратор');
INSERT IGNORE INTO cajeer_permissions (slug, title) VALUES
('admin.access', 'Доступ в админку'),
('content.read', 'Чтение контента'),
('content.write', 'Запись контента'),
('content.delete', 'Удаление контента'),
('marketplace.read', 'Просмотр marketplace'),
('marketplace.install', 'Установка пакетов'),
('settings.write', 'Изменение настроек'),
('compatibility.run', 'Запуск compatibility-инструментов');

INSERT IGNORE INTO cajeer_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM cajeer_roles r CROSS JOIN cajeer_permissions p WHERE r.slug = 'administrator';
