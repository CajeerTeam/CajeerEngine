ALTER TABLE ce_users ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'active';
ALTER TABLE ce_users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ NULL;
ALTER TABLE ce_users ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMPTZ NULL;
ALTER TABLE ce_users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMPTZ NULL;
ALTER TABLE ce_users ADD COLUMN IF NOT EXISTS disabled_at TIMESTAMPTZ NULL;
ALTER TABLE ce_users ADD COLUMN IF NOT EXISTS two_factor_recovery_codes JSONB NOT NULL DEFAULT '[]'::jsonb;

UPDATE ce_users SET status = 'active' WHERE status IS NULL OR status = '';
UPDATE ce_users SET password_changed_at = COALESCE(password_changed_at, created_at);

ALTER TABLE ce_api_tokens ADD COLUMN IF NOT EXISTS token_prefix VARCHAR(20) NULL;
ALTER TABLE ce_api_tokens ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS ce_users_status_idx ON ce_users(status, created_at);
CREATE INDEX IF NOT EXISTS ce_users_last_login_idx ON ce_users(last_login_at);
CREATE INDEX IF NOT EXISTS ce_api_tokens_user_revoked_idx ON ce_api_tokens(user_id, revoked_at, expires_at);
CREATE INDEX IF NOT EXISTS ce_api_tokens_prefix_idx ON ce_api_tokens(token_prefix);

CREATE TABLE IF NOT EXISTS ce_security_events (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    event VARCHAR(255) NOT NULL,
    actor_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    subject_id UUID NULL REFERENCES ce_users(id) ON DELETE SET NULL,
    context JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address INET NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ce_security_events_event_created_idx ON ce_security_events(event, created_at);
CREATE INDEX IF NOT EXISTS ce_security_events_actor_idx ON ce_security_events(actor_id, created_at);
