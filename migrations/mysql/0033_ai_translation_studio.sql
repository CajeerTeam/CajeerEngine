CREATE TABLE ce_ai_translation_proposals (
 id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, type_handle VARCHAR(100) NOT NULL,
 source_entry_id CHAR(36) NOT NULL, source_revision BIGINT NOT NULL, source_hash CHAR(64) NOT NULL,
 target_locale VARCHAR(12) NOT NULL, target_entry_id CHAR(36) NULL, target_revision BIGINT NULL,
 translated_title VARCHAR(255) NOT NULL, translated_data MEDIUMTEXT NOT NULL, batch_item_id CHAR(36) NULL UNIQUE,
 created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, applied_at BIGINT NULL,
 INDEX idx_ai_translation_proposals_owner (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_ai_translation_links (
 translated_entry_id CHAR(36) PRIMARY KEY, source_entry_id CHAR(36) NOT NULL,
 source_revision BIGINT NOT NULL, target_locale VARCHAR(12) NOT NULL, updated_at BIGINT NOT NULL,
 INDEX idx_ai_translation_links_source(source_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_ai_translation_glossary (
 id CHAR(36) PRIMARY KEY, type_handle VARCHAR(100) NOT NULL,
 source_locale VARCHAR(12) NOT NULL, target_locale VARCHAR(12) NOT NULL,
 source_term VARCHAR(160) NOT NULL, translated_term VARCHAR(250) NOT NULL,
 UNIQUE KEY uniq_ai_translation_glossary (type_handle, source_locale, target_locale, source_term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_ai_translation_claims (
 source_entry_id CHAR(36) NOT NULL, target_locale VARCHAR(12) NOT NULL,
 user_id CHAR(36) NOT NULL, expires_at BIGINT NOT NULL,
 PRIMARY KEY(source_entry_id, target_locale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ce_ai_translation_batches (
 id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, type_handle VARCHAR(100) NOT NULL,
 target_locale VARCHAR(12) NOT NULL, created_at BIGINT NOT NULL,
 INDEX idx_ai_translation_batches_owner (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_ai_translation_batch_items (
 id CHAR(36) PRIMARY KEY, batch_id CHAR(36) NOT NULL, source_entry_id CHAR(36) NOT NULL,
 update_existing INTEGER NOT NULL, instructions TEXT NOT NULL, status VARCHAR(20) NOT NULL,
 proposal_id CHAR(36) NULL, error_message TEXT NULL, updated_at BIGINT NOT NULL,
 INDEX idx_ai_translation_batch_items_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
