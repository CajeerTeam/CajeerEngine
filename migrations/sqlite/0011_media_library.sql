ALTER TABLE ce_media ADD COLUMN checksum VARCHAR(64) NOT NULL DEFAULT '';
ALTER TABLE ce_media ADD COLUMN folder VARCHAR(512) NOT NULL DEFAULT '';
ALTER TABLE ce_media ADD COLUMN search_text TEXT;
ALTER TABLE ce_media ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'public';
CREATE INDEX ce_media_checksum_idx ON ce_media (checksum);
CREATE INDEX ce_media_folder_idx ON ce_media (folder);
CREATE INDEX ce_media_created_idx ON ce_media (created_at, id);
