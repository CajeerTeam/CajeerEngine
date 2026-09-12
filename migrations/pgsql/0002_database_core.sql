CREATE TABLE IF NOT EXISTS ce_settings (
    key VARCHAR(255) PRIMARY KEY,
    value JSONB NOT NULL DEFAULT '{}'::jsonb,
    type VARCHAR(40) NOT NULL DEFAULT 'json',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_outbox (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    event_name VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    headers JSONB NOT NULL DEFAULT '{}'::jsonb,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    available_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ce_content_entries_status_idx ON ce_content_entries(status, locale, updated_at);
CREATE INDEX IF NOT EXISTS ce_content_entries_slug_idx ON ce_content_entries(slug, locale);
CREATE INDEX IF NOT EXISTS ce_audit_log_event_created_idx ON ce_audit_log(event, created_at);
CREATE INDEX IF NOT EXISTS ce_outbox_status_available_idx ON ce_outbox(status, available_at);
