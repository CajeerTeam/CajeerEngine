ALTER TABLE ce_content_entries ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT 'Без названия' AFTER content_type_id;
ALTER TABLE ce_content_entries ADD COLUMN revision_number INT NOT NULL DEFAULT 1 AFTER data;
ALTER TABLE ce_content_entries ADD COLUMN translation_group CHAR(36) NULL AFTER locale;
ALTER TABLE ce_content_entries ADD COLUMN deleted_at TIMESTAMP NULL AFTER published_at;

UPDATE ce_content_entries
SET title = COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(data, '$.title')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(data, '$.heading')), ''), title)
WHERE title = 'Без названия';

UPDATE ce_content_entries SET translation_group = id WHERE translation_group IS NULL;
ALTER TABLE ce_content_entries MODIFY translation_group CHAR(36) NOT NULL;

CREATE INDEX ce_content_entries_type_status_locale_idx ON ce_content_entries(content_type_id, status, locale, updated_at);
CREATE INDEX ce_content_entries_translation_group_idx ON ce_content_entries(translation_group, locale);
CREATE INDEX ce_content_entries_deleted_idx ON ce_content_entries(deleted_at);
CREATE INDEX ce_content_revisions_entry_revision_idx ON ce_content_revisions(entry_id, revision_number);
