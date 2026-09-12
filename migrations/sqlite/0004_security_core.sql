UPDATE ce_users SET status = 'active' WHERE status IS NULL OR status = '';
UPDATE ce_users SET password_changed_at = COALESCE(password_changed_at, created_at);
CREATE INDEX IF NOT EXISTS ce_users_status_idx ON ce_users(status, created_at);
CREATE INDEX IF NOT EXISTS ce_users_last_login_idx ON ce_users(last_login_at);
CREATE INDEX IF NOT EXISTS ce_api_tokens_user_revoked_idx ON ce_api_tokens(user_id, revoked_at, expires_at);
CREATE INDEX IF NOT EXISTS ce_api_tokens_prefix_idx ON ce_api_tokens(token_prefix);
CREATE INDEX IF NOT EXISTS ce_security_events_event_created_idx ON ce_security_events(event, created_at);
CREATE INDEX IF NOT EXISTS ce_security_events_actor_idx ON ce_security_events(actor_id, created_at);
