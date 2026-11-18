-- 1.6.8: visitor capability tokens, operator queue and isolated support transcript.
CREATE TABLE ce_support_tickets (
 id VARCHAR(36) PRIMARY KEY,
 access_hash VARCHAR(64) NOT NULL,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(254) NOT NULL,
 subject VARCHAR(160) NOT NULL,
 locale VARCHAR(12) NOT NULL,
 status VARCHAR(12) NOT NULL,
 priority VARCHAR(12) NOT NULL,
 assigned_to VARCHAR(36) NULL,
 needs_human INTEGER NOT NULL DEFAULT 1,
 revision INTEGER NOT NULL DEFAULT 1,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL
);
CREATE INDEX idx_support_queue ON ce_support_tickets (status, needs_human, updated_at);
CREATE INDEX idx_support_assignee ON ce_support_tickets (assigned_to, updated_at);
CREATE TABLE ce_support_messages (
 id VARCHAR(36) PRIMARY KEY,
 ticket_id VARCHAR(36) NOT NULL,
 role VARCHAR(12) NOT NULL,
 author_id VARCHAR(36) NULL,
 content TEXT NOT NULL,
 sources_json TEXT NOT NULL,
 created_at BIGINT NOT NULL,
 FOREIGN KEY (ticket_id) REFERENCES ce_support_tickets (id) ON DELETE CASCADE
);
CREATE INDEX idx_support_transcript ON ce_support_messages (ticket_id, created_at);
