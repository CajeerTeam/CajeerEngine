CREATE TABLE ce_ai_editor_jobs (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    period VARCHAR(7) NOT NULL,
    reserved_tokens BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    INDEX idx_ai_editor_jobs_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_ai_editor_proposals (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    type_handle VARCHAR(100) NOT NULL,
    entry_id CHAR(36) NULL,
    target_field VARCHAR(100) NOT NULL,
    field_type VARCHAR(20) NOT NULL,
    mode VARCHAR(20) NOT NULL,
    original_hash CHAR(64) NOT NULL,
    proposed_text TEXT NOT NULL,
    selected_text TEXT NULL,
    block_index INTEGER NULL,
    block_field VARCHAR(100) NULL,
    expected_revision BIGINT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    applied_at BIGINT NULL,
    INDEX idx_ai_editor_proposals_owner (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
