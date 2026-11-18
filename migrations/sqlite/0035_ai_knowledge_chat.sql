-- 1.6.7: private, owner-scoped RAG history; sources are verified on every read.
CREATE TABLE ce_ai_kb_conversations (
 id VARCHAR(36) PRIMARY KEY,
 user_id VARCHAR(36) NOT NULL,
 title VARCHAR(160) NOT NULL,
 visibility VARCHAR(12) NOT NULL,
 locale VARCHAR(12) NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL
);
CREATE INDEX idx_ai_kb_conversations_user ON ce_ai_kb_conversations(user_id, updated_at);
CREATE TABLE ce_ai_kb_messages (
 id VARCHAR(36) PRIMARY KEY,
 conversation_id VARCHAR(36) NOT NULL,
 role VARCHAR(12) NOT NULL,
 content TEXT NOT NULL,
 sources_json TEXT NOT NULL,
 created_at BIGINT NOT NULL,
 input_tokens INTEGER NOT NULL,
 output_tokens INTEGER NOT NULL,
 feedback INTEGER NULL,
 FOREIGN KEY (conversation_id) REFERENCES ce_ai_kb_conversations(id) ON DELETE CASCADE
);
CREATE INDEX idx_ai_kb_messages_conversation ON ce_ai_kb_messages(conversation_id, created_at);
