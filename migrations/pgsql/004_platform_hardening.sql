ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS meta_title VARCHAR(500) NULL;
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS meta_description TEXT NULL;
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS canonical_url VARCHAR(500) NULL;
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS cover_image VARCHAR(500) NULL;
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS category_id BIGINT NULL;
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS visibility VARCHAR(32) NOT NULL DEFAULT 'public';
ALTER TABLE cajeer_content ADD COLUMN IF NOT EXISTS revision_id BIGINT NULL;
CREATE INDEX IF NOT EXISTS idx_cajeer_content_status_pub ON cajeer_content(status, published_at);

CREATE TABLE IF NOT EXISTS cajeer_tags (
    id BIGSERIAL PRIMARY KEY,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_content_tags (
    content_id BIGINT NOT NULL,
    tag_id BIGINT NOT NULL,
    PRIMARY KEY (content_id, tag_id)
);

CREATE TABLE IF NOT EXISTS cajeer_content_categories (
    content_id BIGINT NOT NULL,
    category_id BIGINT NOT NULL,
    PRIMARY KEY (content_id, category_id)
);

CREATE TABLE IF NOT EXISTS cajeer_content_revisions (
    id BIGSERIAL PRIMARY KEY,
    content_id BIGINT NOT NULL,
    snapshot_json JSONB NULL,
    actor_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cajeer_content_revisions_content ON cajeer_content_revisions(content_id);

CREATE OR REPLACE FUNCTION cajeer_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_cajeer_content_updated_at ON cajeer_content;
CREATE TRIGGER trg_cajeer_content_updated_at BEFORE UPDATE ON cajeer_content FOR EACH ROW EXECUTE FUNCTION cajeer_set_updated_at();
DROP TRIGGER IF EXISTS trg_cajeer_users_updated_at ON cajeer_users;
CREATE TRIGGER trg_cajeer_users_updated_at BEFORE UPDATE ON cajeer_users FOR EACH ROW EXECUTE FUNCTION cajeer_set_updated_at();
DROP TRIGGER IF EXISTS trg_cajeer_settings_updated_at ON cajeer_settings;
CREATE TRIGGER trg_cajeer_settings_updated_at BEFORE UPDATE ON cajeer_settings FOR EACH ROW EXECUTE FUNCTION cajeer_set_updated_at();

INSERT INTO cajeer_roles (slug, title) VALUES ('administrator', 'Администратор') ON CONFLICT (slug) DO NOTHING;
INSERT INTO cajeer_permissions (slug, title) VALUES
('admin.access', 'Доступ в админку'),
('content.read', 'Чтение контента'),
('content.write', 'Запись контента'),
('content.delete', 'Удаление контента'),
('marketplace.read', 'Просмотр marketplace'),
('marketplace.install', 'Установка пакетов'),
('settings.write', 'Изменение настроек'),
('compatibility.run', 'Запуск compatibility-инструментов') ON CONFLICT (slug) DO NOTHING;

INSERT INTO cajeer_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM cajeer_roles r CROSS JOIN cajeer_permissions p WHERE r.slug = 'administrator'
ON CONFLICT DO NOTHING;
