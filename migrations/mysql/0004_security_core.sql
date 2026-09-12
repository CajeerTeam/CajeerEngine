ALTER TABLE ce_users ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'active';
ALTER TABLE ce_users ADD COLUMN email_verified_at TIMESTAMP NULL;
ALTER TABLE ce_users ADD COLUMN password_changed_at TIMESTAMP NULL;
ALTER TABLE ce_users ADD COLUMN last_login_at TIMESTAMP NULL;
ALTER TABLE ce_users ADD COLUMN disabled_at TIMESTAMP NULL;
ALTER TABLE ce_users ADD COLUMN two_factor_recovery_codes JSON NOT NULL;

UPDATE ce_users SET status = 'active' WHERE status IS NULL OR status = '';
UPDATE ce_users SET password_changed_at = COALESCE(password_changed_at, created_at);
UPDATE ce_users SET two_factor_recovery_codes = JSON_ARRAY() WHERE two_factor_recovery_codes IS NULL;

ALTER TABLE ce_api_tokens ADD COLUMN token_prefix VARCHAR(20) NULL;
ALTER TABLE ce_api_tokens ADD COLUMN revoked_at TIMESTAMP NULL;

CREATE INDEX ce_users_status_idx ON ce_users(status, created_at);
CREATE INDEX ce_users_last_login_idx ON ce_users(last_login_at);
CREATE INDEX ce_api_tokens_user_revoked_idx ON ce_api_tokens(user_id, revoked_at, expires_at);
CREATE INDEX ce_api_tokens_prefix_idx ON ce_api_tokens(token_prefix);

CREATE TABLE IF NOT EXISTS ce_security_events (
    id CHAR(36) PRIMARY KEY,
    event VARCHAR(255) NOT NULL,
    actor_id CHAR(36) NULL,
    subject_id CHAR(36) NULL,
    context JSON NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ce_security_events_actor_fk FOREIGN KEY (actor_id) REFERENCES ce_users(id) ON DELETE SET NULL,
    CONSTRAINT ce_security_events_subject_fk FOREIGN KEY (subject_id) REFERENCES ce_users(id) ON DELETE SET NULL,
    INDEX ce_security_events_event_created_idx (event, created_at),
    INDEX ce_security_events_actor_idx (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
