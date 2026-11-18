-- Session metadata is separate from opaque API tokens. Token validity remains authoritative.
CREATE TABLE ce_user_sessions (
    token_id TEXT PRIMARY KEY REFERENCES ce_api_tokens(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    provider TEXT NOT NULL CHECK (provider IN ('local','skif4er','legacy')),
    user_agent TEXT NOT NULL DEFAULT '',
    ip_hint TEXT NOT NULL DEFAULT '',
    issuer TEXT NULL,
    oidc_sid_hash TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT NULL,
    revoked_at TEXT NULL
);
CREATE INDEX ce_user_sessions_owner_active_idx ON ce_user_sessions(user_id, revoked_at, last_seen_at);
CREATE INDEX ce_user_sessions_sid_idx ON ce_user_sessions(issuer, oidc_sid_hash);
