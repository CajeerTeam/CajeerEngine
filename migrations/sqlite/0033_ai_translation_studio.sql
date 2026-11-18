CREATE TABLE ce_ai_translation_proposals (
 id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, type_handle VARCHAR(100) NOT NULL,
 source_entry_id VARCHAR(36) NOT NULL, source_revision BIGINT NOT NULL, source_hash VARCHAR(64) NOT NULL,
 target_locale VARCHAR(12) NOT NULL, target_entry_id VARCHAR(36) NULL, target_revision BIGINT NULL,
 translated_title VARCHAR(255) NOT NULL, translated_data TEXT NOT NULL, batch_item_id VARCHAR(36) NULL UNIQUE,
 created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, applied_at BIGINT NULL
);
CREATE INDEX idx_ai_translation_proposals_owner ON ce_ai_translation_proposals(user_id, expires_at);
CREATE TABLE ce_ai_translation_links (
 translated_entry_id VARCHAR(36) PRIMARY KEY, source_entry_id VARCHAR(36) NOT NULL,
 source_revision BIGINT NOT NULL, target_locale VARCHAR(12) NOT NULL, updated_at BIGINT NOT NULL
);
CREATE INDEX idx_ai_translation_links_source ON ce_ai_translation_links(source_entry_id);
CREATE TABLE ce_ai_translation_glossary (
 id VARCHAR(36) PRIMARY KEY, type_handle VARCHAR(100) NOT NULL,
 source_locale VARCHAR(12) NOT NULL, target_locale VARCHAR(12) NOT NULL,
 source_term VARCHAR(160) NOT NULL, translated_term VARCHAR(250) NOT NULL,
 UNIQUE(type_handle, source_locale, target_locale, source_term)
);
CREATE TABLE ce_ai_translation_claims (
 source_entry_id VARCHAR(36) NOT NULL, target_locale VARCHAR(12) NOT NULL,
 user_id VARCHAR(36) NOT NULL, expires_at BIGINT NOT NULL,
 PRIMARY KEY(source_entry_id, target_locale)
);

CREATE TABLE ce_ai_translation_batches (
 id VARCHAR(36) PRIMARY KEY, user_id VARCHAR(36) NOT NULL, type_handle VARCHAR(100) NOT NULL,
 target_locale VARCHAR(12) NOT NULL, created_at BIGINT NOT NULL
);
CREATE INDEX idx_ai_translation_batches_owner ON ce_ai_translation_batches(user_id, created_at);
CREATE TABLE ce_ai_translation_batch_items (
 id VARCHAR(36) PRIMARY KEY, batch_id VARCHAR(36) NOT NULL, source_entry_id VARCHAR(36) NOT NULL,
 update_existing INTEGER NOT NULL, instructions TEXT NOT NULL, status VARCHAR(20) NOT NULL,
 proposal_id VARCHAR(36) NULL, error_message TEXT NULL, updated_at BIGINT NOT NULL
);
CREATE INDEX idx_ai_translation_batch_items_batch ON ce_ai_translation_batch_items(batch_id);
