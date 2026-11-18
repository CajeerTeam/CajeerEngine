CREATE TABLE ce_ai_conversations (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    title VARCHAR(160) NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    busy_until BIGINT NOT NULL DEFAULT 0,
    reserved_tokens BIGINT NOT NULL DEFAULT 0,
    reservation_period VARCHAR(7) NULL,
    total_input_tokens BIGINT NOT NULL DEFAULT 0,
    total_output_tokens BIGINT NOT NULL DEFAULT 0
);
CREATE INDEX idx_ai_conversations_owner ON ce_ai_conversations (user_id, updated_at);
CREATE TABLE ce_ai_messages (
    id VARCHAR(36) PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    role VARCHAR(16) NOT NULL,
    content TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    model VARCHAR(80) NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (conversation_id) REFERENCES ce_ai_conversations (id) ON DELETE CASCADE
);
CREATE INDEX idx_ai_messages_conversation ON ce_ai_messages (conversation_id, created_at);
CREATE TABLE ce_ai_usage (
    user_id VARCHAR(36) NOT NULL,
    period VARCHAR(7) NOT NULL,
    used_tokens BIGINT NOT NULL DEFAULT 0,
    reserved_tokens BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, period)
);
