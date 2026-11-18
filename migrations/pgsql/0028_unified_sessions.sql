CREATE TABLE ce_user_sessions (
    token_id UUID PRIMARY KEY REFERENCES ce_api_tokens(id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES ce_users(id) ON DELETE CASCADE,
    provider VARCHAR(20) NOT NULL CHECK (provider IN ('local','skif4er','legacy')),
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    ip_hint VARCHAR(64) NOT NULL DEFAULT '',
    issuer VARCHAR(255) NULL,
    oidc_sid_hash CHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NULL,
    revoked_at TIMESTAMPTZ NULL
);
CREATE INDEX ce_user_sessions_owner_active_idx ON ce_user_sessions(user_id, revoked_at, last_seen_at);
CREATE INDEX ce_user_sessions_sid_idx ON ce_user_sessions(issuer, oidc_sid_hash);
