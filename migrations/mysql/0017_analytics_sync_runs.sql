-- Durable synchronization receipts and bounded operational history.
CREATE TABLE IF NOT EXISTS analytics_sync_runs (
    connection_id VARCHAR(32) NOT NULL,
    revision INTEGER NOT NULL,
    job_key VARCHAR(64) NOT NULL,
    date_from VARCHAR(10) NOT NULL,
    date_to VARCHAR(10) NOT NULL,
    status VARCHAR(16) NOT NULL,
    attempts INTEGER NOT NULL,
    started_at VARCHAR(40) NOT NULL,
    finished_at VARCHAR(40) NULL,
    error VARCHAR(200) NULL,
    PRIMARY KEY (connection_id, revision, job_key)
);
