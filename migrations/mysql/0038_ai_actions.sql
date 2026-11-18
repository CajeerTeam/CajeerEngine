-- 1.7.2: durable approval queue. No model-selected action may execute before user confirmation.
CREATE TABLE ce_ai_actions (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    action_name VARCHAR(80) NOT NULL,
    payload_json TEXT NOT NULL,
    before_json TEXT NOT NULL,
    expected_revision INTEGER NULL,
    state VARCHAR(12) NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    decided_at BIGINT NULL,
    result_json TEXT NULL
);
CREATE INDEX idx_ai_actions_user_state ON ce_ai_actions(user_id, state, created_at);
