CREATE INDEX IF NOT EXISTS ce_entries_type_live_updated_idx ON ce_content_entries(content_type_id, deleted_at, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS ce_entries_type_locale_live_updated_idx ON ce_content_entries(content_type_id, locale, deleted_at, updated_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS ce_entries_type_status_live_updated_idx ON ce_content_entries(content_type_id, status, deleted_at, updated_at DESC, id DESC);
