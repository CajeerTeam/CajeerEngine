CREATE INDEX IF NOT EXISTS ce_content_entries_status_idx ON ce_content_entries(status, locale, updated_at);
CREATE INDEX IF NOT EXISTS ce_content_entries_slug_idx ON ce_content_entries(slug, locale);
CREATE INDEX IF NOT EXISTS ce_audit_log_event_created_idx ON ce_audit_log(event, created_at);
CREATE INDEX IF NOT EXISTS ce_outbox_status_available_idx ON ce_outbox(status, available_at);
