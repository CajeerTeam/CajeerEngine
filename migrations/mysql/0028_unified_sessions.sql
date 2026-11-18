CREATE TABLE ce_user_sessions (
    token_id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    provider VARCHAR(20) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    ip_hint VARCHAR(64) NOT NULL DEFAULT '',
    issuer VARCHAR(255) NULL,
    oidc_sid_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    INDEX ce_user_sessions_owner_active_idx (user_id, revoked_at, last_seen_at),
    INDEX ce_user_sessions_sid_idx (issuer, oidc_sid_hash),
    CONSTRAINT ce_session_token_fk FOREIGN KEY (token_id) REFERENCES ce_api_tokens(id) ON DELETE CASCADE,
    CONSTRAINT ce_session_user_fk FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
