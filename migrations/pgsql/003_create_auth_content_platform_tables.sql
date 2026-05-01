CREATE TABLE IF NOT EXISTS cajeer_roles (
    id BIGSERIAL PRIMARY KEY,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_permissions (
    id BIGSERIAL PRIMARY KEY,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cajeer_role_permissions (
    role_id BIGINT NOT NULL,
    permission_id BIGINT NOT NULL,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE IF NOT EXISTS cajeer_user_roles (
    user_id BIGINT NOT NULL,
    role_id BIGINT NOT NULL,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS cajeer_comments (
    id BIGSERIAL PRIMARY KEY,
    content_id BIGINT NOT NULL,
    author_name VARCHAR(255) NULL,
    author_email VARCHAR(255) NULL,
    body TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cajeer_comments_content_status ON cajeer_comments(content_id, status);

CREATE TABLE IF NOT EXISTS cajeer_media (
    id BIGSERIAL PRIMARY KEY,
    disk VARCHAR(64) NOT NULL DEFAULT 'local',
    path VARCHAR(500) NOT NULL,
    mime VARCHAR(191) NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cajeer_media_disk ON cajeer_media(disk);
