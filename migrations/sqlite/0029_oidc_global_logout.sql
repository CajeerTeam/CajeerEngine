-- Track OIDC subject for precise back-channel logout without retaining its raw value.
ALTER TABLE ce_user_sessions ADD COLUMN oidc_sub_hash TEXT NULL;
CREATE INDEX ce_user_sessions_sub_idx ON ce_user_sessions(issuer, oidc_sub_hash);
CREATE TABLE ce_oidc_logout_replay (
    jti_hash TEXT PRIMARY KEY,
    issuer TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ce_oidc_logout_expiry_idx ON ce_oidc_logout_replay(expires_at);
