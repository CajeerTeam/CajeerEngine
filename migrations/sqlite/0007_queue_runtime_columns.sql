CREATE INDEX IF NOT EXISTS ce_jobs_queue_reserved_idx ON ce_jobs(queue, reserved_at, available_at);
CREATE INDEX IF NOT EXISTS ce_jobs_status_queue_available_idx ON ce_jobs(status, queue, available_at);
