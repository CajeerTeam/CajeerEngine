-- 1.7.8: Durable owner-scoped AI schedules and individual at-most-once run records.
CREATE TABLE ce_ai_automations (
    id VARCHAR(36) PRIMARY KEY,
    user_id UUID NOT NULL,
    title VARCHAR(160) NOT NULL,
    prompt TEXT NOT NULL,
    interval_minutes INTEGER NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    revision INTEGER NOT NULL DEFAULT 1,
    next_run_at BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    deleted_at BIGINT NULL,
    FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE
);
CREATE INDEX idx_ai_automations_due ON ce_ai_automations(enabled, next_run_at);
CREATE INDEX idx_ai_automations_owner ON ce_ai_automations(user_id, created_at);
CREATE TABLE ce_ai_automation_runs (
    id VARCHAR(36) PRIMARY KEY,
    automation_id VARCHAR(36) NOT NULL,
    user_id UUID NOT NULL,
    prompt_snapshot TEXT NOT NULL,
    scheduled_for BIGINT NULL,
    status VARCHAR(16) NOT NULL,
    conversation_id VARCHAR(36) NULL,
    queued_at BIGINT NOT NULL,
    started_at BIGINT NULL,
    finished_at BIGINT NULL,
    error VARCHAR(220) NULL,
    FOREIGN KEY (automation_id) REFERENCES ce_ai_automations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES ce_users(id) ON DELETE CASCADE,
    UNIQUE(automation_id, scheduled_for)
);
CREATE INDEX idx_ai_automation_runs_owner ON ce_ai_automation_runs(user_id, queued_at);
CREATE INDEX idx_ai_automation_runs_status ON ce_ai_automation_runs(status, queued_at);
