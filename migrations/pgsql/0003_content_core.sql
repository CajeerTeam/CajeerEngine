ALTER TABLE ce_content_entries ADD COLUMN IF NOT EXISTS title VARCHAR(255) NOT NULL DEFAULT 'Без названия';
ALTER TABLE ce_content_entries ADD COLUMN IF NOT EXISTS revision_number INT NOT NULL DEFAULT 1;
ALTER TABLE ce_content_entries ADD COLUMN IF NOT EXISTS translation_group UUID NULL;
ALTER TABLE ce_content_entries ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

UPDATE ce_content_entries
SET title = COALESCE(NULLIF(data->>'title', ''), NULLIF(data->>'name', ''), NULLIF(data->>'heading', ''), title)
WHERE title = 'Без названия';

UPDATE ce_content_entries
SET translation_group = id
WHERE translation_group IS NULL;

ALTER TABLE ce_content_entries ALTER COLUMN translation_group SET NOT NULL;

CREATE INDEX IF NOT EXISTS ce_content_entries_type_status_locale_idx ON ce_content_entries(content_type_id, status, locale, updated_at);
CREATE INDEX IF NOT EXISTS ce_content_entries_translation_group_idx ON ce_content_entries(translation_group, locale);
CREATE INDEX IF NOT EXISTS ce_content_entries_deleted_idx ON ce_content_entries(deleted_at);

CREATE INDEX IF NOT EXISTS ce_content_revisions_entry_revision_idx ON ce_content_revisions(entry_id, revision_number DESC);
