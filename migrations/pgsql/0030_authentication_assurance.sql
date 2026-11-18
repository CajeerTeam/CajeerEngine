-- Authentication assurance is bound to a revocable session, never to a user or global cookie.
ALTER TABLE ce_user_sessions ADD COLUMN assured_at BIGINT NULL;
ALTER TABLE ce_user_sessions ADD COLUMN assurance_method VARCHAR(24) NULL;
ALTER TABLE ce_user_sessions ADD COLUMN assurance_mfa SMALLINT NOT NULL DEFAULT 0;
