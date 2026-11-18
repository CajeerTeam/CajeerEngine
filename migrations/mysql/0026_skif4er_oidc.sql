-- One-time browser-bound OAuth transactions and explicit operator-owned OIDC links.
CREATE TABLE ce_oidc_transactions (
    state_hash CHAR(64) NOT NULL PRIMARY KEY,
    browser_hash CHAR(64) NOT NULL,
    nonce VARCHAR(128) NOT NULL,
    verifier_ciphertext TEXT NOT NULL,
    expires_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX ce_oidc_transactions_expiry ON ce_oidc_transactions(expires_at);
CREATE TABLE ce_oidc_identities (
    issuer VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    user_id CHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (issuer, subject),
    UNIQUE (issuer, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
