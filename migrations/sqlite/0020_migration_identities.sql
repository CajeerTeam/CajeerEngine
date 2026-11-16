-- Durable identity creation and one-time activation state. No credentials in receipts.
CREATE TABLE IF NOT EXISTS ce_migration_identities (
    project_id VARCHAR(32) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    user_id VARCHAR(36) NOT NULL UNIQUE,
    role_handle VARCHAR(100) NOT NULL,
    state VARCHAR(20) NOT NULL,
    created_at VARCHAR(40) NOT NULL,
    activated_at VARCHAR(40),
    PRIMARY KEY (project_id, source_id)
);
