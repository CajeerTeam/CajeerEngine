CREATE TABLE IF NOT EXISTS ce_release_locks (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    version VARCHAR(32) NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    engine_api_version VARCHAR(32) NOT NULL DEFAULT '1.0',
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ce_support_bundles (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    filename VARCHAR(255) NOT NULL,
    sha256 VARCHAR(64) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ce_release_locks_version ON ce_release_locks(version);
CREATE INDEX IF NOT EXISTS idx_ce_support_bundles_created_at ON ce_support_bundles(created_at);
