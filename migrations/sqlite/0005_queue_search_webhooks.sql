CREATE INDEX IF NOT EXISTS ce_failed_jobs_queue_failed_idx ON ce_failed_jobs(queue, failed_at);
CREATE INDEX IF NOT EXISTS ce_webhook_deliveries_webhook_created_idx ON ce_webhook_deliveries(webhook_id, created_at);
CREATE INDEX IF NOT EXISTS ce_webhook_deliveries_event_created_idx ON ce_webhook_deliveries(event_name, created_at);
