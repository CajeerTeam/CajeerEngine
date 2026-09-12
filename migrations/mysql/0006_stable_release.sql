CREATE TABLE IF NOT EXISTS ce_release_locks (
    id CHAR(36) PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    engine_api_version VARCHAR(32) NOT NULL DEFAULT '1.0',
    payload JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_support_bundles (
    id CHAR(36) PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ce_release_locks_version ON ce_release_locks(version);
CREATE INDEX idx_ce_support_bundles_created_at ON ce_support_bundles(created_at);
