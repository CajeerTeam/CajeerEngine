-- 1.7.1: minimal audit trace for executed CMS function calls (never stores arguments or tool output).
CREATE TABLE ce_ai_tool_calls (
    id VARCHAR(36) PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    assistant_message_id VARCHAR(36) NOT NULL,
    tool_name VARCHAR(80) NOT NULL,
    status VARCHAR(12) NOT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    FOREIGN KEY (conversation_id) REFERENCES ce_ai_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (assistant_message_id) REFERENCES ce_ai_messages(id) ON DELETE CASCADE
);
CREATE INDEX idx_ai_tool_calls_message ON ce_ai_tool_calls(assistant_message_id, created_at);
