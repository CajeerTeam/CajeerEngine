-- AI Knowledge Base 1.6.6: completely separate from ce_search_documents.
CREATE TABLE ce_kb_categories (
 id CHAR(36) PRIMARY KEY,
 slug VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(160) NOT NULL,
 description TEXT NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_kb_documents (
 id CHAR(36) PRIMARY KEY,
 category_id CHAR(36) NULL,
 slug VARCHAR(80) NOT NULL UNIQUE,
 title VARCHAR(240) NOT NULL,
 body MEDIUMTEXT NOT NULL,
 locale VARCHAR(12) NOT NULL,
 visibility VARCHAR(12) NOT NULL,
 status VARCHAR(12) NOT NULL,
 source_kind VARCHAR(12) NOT NULL,
 source_type VARCHAR(100) NULL,
 source_id CHAR(36) NULL,
 source_revision BIGINT NULL,
 revision BIGINT NOT NULL,
 indexed_revision BIGINT NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 UNIQUE (source_kind, source_type, source_id),
 FOREIGN KEY (category_id) REFERENCES ce_kb_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_kb_chunks (
 document_id CHAR(36) NOT NULL,
 chunk_no INTEGER NOT NULL,
 content TEXT NOT NULL,
 PRIMARY KEY (document_id, chunk_no),
 FOREIGN KEY (document_id) REFERENCES ce_kb_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ce_kb_terms (
 term VARCHAR(64) NOT NULL,
 document_id CHAR(36) NOT NULL,
 chunk_no INTEGER NOT NULL,
 frequency INTEGER NOT NULL,
 PRIMARY KEY (term, document_id, chunk_no),
 FOREIGN KEY (document_id) REFERENCES ce_kb_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_kb_documents_category ON ce_kb_documents(category_id, updated_at);
CREATE INDEX idx_kb_documents_visibility ON ce_kb_documents(visibility, status, locale);
CREATE INDEX idx_kb_terms_document ON ce_kb_terms(document_id);
