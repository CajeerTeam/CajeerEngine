ALTER TABLE ce_user_sessions ADD COLUMN oidc_sub_hash CHAR(64) NULL;
CREATE INDEX ce_user_sessions_sub_idx ON ce_user_sessions(issuer, oidc_sub_hash);
CREATE TABLE ce_oidc_logout_replay (
    jti_hash CHAR(64) PRIMARY KEY,
    issuer VARCHAR(255) NOT NULL,
    expires_at BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ce_oidc_logout_expiry_idx ON ce_oidc_logout_replay(expires_at);
