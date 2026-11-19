-- 1.7.6: operator-confirmed, one-time remediation proposals and audit receipts.
CREATE TABLE ce_ai_migration_remediations (
    id VARCHAR(36) PRIMARY KEY,
    project_id VARCHAR(32) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    snapshot_json TEXT NOT NULL,
    state VARCHAR(12) NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    decided_at BIGINT NULL,
    result_json TEXT NULL
);
CREATE INDEX idx_ai_migration_remediation_pending ON ce_ai_migration_remediations(project_id, user_id, state, created_at);
