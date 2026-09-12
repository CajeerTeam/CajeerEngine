UPDATE ce_content_entries SET translation_group = id WHERE translation_group = '' OR translation_group IS NULL;
CREATE INDEX IF NOT EXISTS ce_content_entries_type_status_locale_idx ON ce_content_entries(content_type_id, status, locale, updated_at);
CREATE INDEX IF NOT EXISTS ce_content_entries_translation_group_idx ON ce_content_entries(translation_group, locale);
CREATE INDEX IF NOT EXISTS ce_content_entries_deleted_idx ON ce_content_entries(deleted_at);
CREATE INDEX IF NOT EXISTS ce_content_revisions_entry_revision_idx ON ce_content_revisions(entry_id, revision_number DESC);
