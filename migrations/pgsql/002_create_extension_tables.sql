CREATE TABLE IF NOT EXISTS cajeer_extensions (
    id BIGSERIAL PRIMARY KEY,
    package_name VARCHAR(191) NOT NULL UNIQUE,
    type VARCHAR(64) NOT NULL,
    version VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'disabled',
    manifest_json JSONB NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cajeer_extensions_type_status ON cajeer_extensions(type, status);

CREATE TABLE IF NOT EXISTS cajeer_audit_log (
    id BIGSERIAL PRIMARY KEY,
    actor_id BIGINT NULL,
    event VARCHAR(191) NOT NULL,
    target_type VARCHAR(191) NULL,
    target_id VARCHAR(191) NULL,
    payload_json JSONB NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cajeer_audit_event ON cajeer_audit_log(event);
CREATE INDEX IF NOT EXISTS idx_cajeer_audit_actor ON cajeer_audit_log(actor_id);
