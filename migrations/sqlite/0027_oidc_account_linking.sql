-- Link authorization survives redirects and can be checked against the issuing local session.
ALTER TABLE ce_oidc_transactions ADD COLUMN purpose VARCHAR(12) NOT NULL DEFAULT 'login';
ALTER TABLE ce_oidc_transactions ADD COLUMN user_id CHAR(36) NULL;
ALTER TABLE ce_oidc_transactions ADD COLUMN session_id CHAR(36) NULL;
-- Prevent the sole OIDC login from being unlinked without a verified local credential.
ALTER TABLE ce_oidc_identities ADD COLUMN provisioned BOOLEAN NOT NULL DEFAULT FALSE;
