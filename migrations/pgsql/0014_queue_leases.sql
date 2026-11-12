ALTER TABLE ce_jobs ADD COLUMN reservation_token VARCHAR(96) NULL;
ALTER TABLE ce_jobs ADD COLUMN lease_until TIMESTAMPTZ NULL;
CREATE INDEX ce_jobs_lease_recovery_idx ON ce_jobs(queue, lease_until, id) WHERE status = 'reserved';
CREATE INDEX ce_jobs_ready_idx ON ce_jobs(queue, available_at, created_at, id) WHERE status = 'pending';
CREATE TABLE ce_scheduler_runs (
    name VARCHAR(255) PRIMARY KEY,
    state JSONB NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
