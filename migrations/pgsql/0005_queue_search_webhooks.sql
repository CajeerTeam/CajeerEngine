ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'pending';
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS last_error TEXT NULL;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS failed_at TIMESTAMPTZ NULL;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS processed_at TIMESTAMPTZ NULL;
ALTER TABLE ce_jobs ADD COLUMN IF NOT EXISTS max_attempts INT NOT NULL DEFAULT 3;

CREATE TABLE IF NOT EXISTS ce_failed_jobs (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    queue VARCHAR(100) NOT NULL DEFAULT 'default',
    name VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    attempts INT NOT NULL DEFAULT 0,
    error TEXT NULL,
    failed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ce_webhook_deliveries (
    id UUID PRIMARY KEY DEFAULT (md5(random()::text || clock_timestamp()::text))::uuid,
    webhook_id UUID NULL,
    event_name VARCHAR(255) NOT NULL,
    url TEXT NOT NULL,
    status_code INT NOT NULL DEFAULT 0,
    ok BOOLEAN NOT NULL DEFAULT FALSE,
    error TEXT NULL,
    response_excerpt TEXT NULL,
    duration_ms NUMERIC(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ce_failed_jobs_queue_failed_idx ON ce_failed_jobs(queue, failed_at);
CREATE INDEX IF NOT EXISTS ce_webhook_deliveries_webhook_created_idx ON ce_webhook_deliveries(webhook_id, created_at);
CREATE INDEX IF NOT EXISTS ce_webhook_deliveries_event_created_idx ON ce_webhook_deliveries(event_name, created_at);
