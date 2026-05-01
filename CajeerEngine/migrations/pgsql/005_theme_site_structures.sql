CREATE TABLE IF NOT EXISTS cajeer_menus (
    id BIGSERIAL PRIMARY KEY,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_menu_items (
    id BIGSERIAL PRIMARY KEY,
    menu_id BIGINT NOT NULL,
    parent_id BIGINT NULL,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    target VARCHAR(32) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cajeer_menu_items_menu_order ON cajeer_menu_items(menu_id, sort_order);

CREATE TABLE IF NOT EXISTS cajeer_blocks (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(64) NOT NULL,
    title VARCHAR(255) NULL,
    payload_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_page_blocks (
    id BIGSERIAL PRIMARY KEY,
    content_id BIGINT NOT NULL,
    block_id BIGINT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cajeer_page_blocks_content_order ON cajeer_page_blocks(content_id, sort_order);

INSERT INTO cajeer_settings (setting_key, setting_value, autoload) VALUES
('active_theme', 'cajeer-official', 1),
('site.name', 'Cajeer', 1),
('site.domain', 'https://cajeer.ru', 1),
('site.language', 'ru', 1),
('site.theme_color', '#0b0f19', 1),
('brand.founder', 'SkiF4er', 1),
('brand.public_voice', 'Cajeer не ведёт публичной коммуникации как организация. Единственным публичным представителем является SkiF4er.', 1),
('brand.remote_model', 'Cajeer — полностью удалённая команда без офиса и штаб-квартиры. Все сотрудники работают удалённо.', 1),
('support.email', 'support@cajeer.ru', 1),
('support.telegram_bot', 'https://t.me/CajeerBot', 1)
ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, autoload = EXCLUDED.autoload, updated_at = CURRENT_TIMESTAMP;

INSERT INTO cajeer_content (type, status, slug, title, excerpt, body, sort_order, meta_title, meta_description, published_at)
VALUES
('project', 'published', 'nevermine', 'NeverMine', 'Официально анонсированный проект экосистемы Cajeer.', 'NeverMine — официальный проект экосистемы Cajeer, размещённый в публичном перечне анонсированных проектов.', 10, 'NeverMine — проект Cajeer', 'NeverMine — официальный проект экосистемы Cajeer.', CURRENT_TIMESTAMP),
('project', 'published', 'candyrp', 'CandyRP', 'Официально анонсированный проект экосистемы Cajeer.', 'CandyRP — официальный проект экосистемы Cajeer, размещённый в публичном перечне анонсированных проектов.', 20, 'CandyRP — проект Cajeer', 'CandyRP — официальный проект экосистемы Cajeer.', CURRENT_TIMESTAMP)
ON CONFLICT (slug) DO UPDATE SET title = EXCLUDED.title, excerpt = EXCLUDED.excerpt, body = EXCLUDED.body, sort_order = EXCLUDED.sort_order, updated_at = CURRENT_TIMESTAMP;
