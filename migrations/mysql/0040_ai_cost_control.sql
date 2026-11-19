-- Dollar micros avoid floating-point accounting. Ledger rows retain historic model prices.
CREATE TABLE ce_ai_cost_balances (
    scope VARCHAR(100) NOT NULL,
    period VARCHAR(10) NOT NULL,
    used_micro_usd BIGINT NOT NULL DEFAULT 0,
    reserved_micro_usd BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, period)
);
CREATE TABLE ce_ai_cost_reservations (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(80) NOT NULL,
    module VARCHAR(40) NOT NULL,
    model VARCHAR(80) NOT NULL,
    day_period VARCHAR(10) NOT NULL,
    month_period VARCHAR(7) NOT NULL,
    reserved_micro_usd BIGINT NOT NULL,
    input_rate_cents INTEGER NOT NULL,
    cached_rate_cents INTEGER NOT NULL,
    output_rate_cents INTEGER NOT NULL,
    expires_at BIGINT NOT NULL,
    created_at BIGINT NOT NULL
);
CREATE INDEX idx_ai_cost_reservations_expiry ON ce_ai_cost_reservations (expires_at);
CREATE INDEX idx_ai_cost_reservations_user ON ce_ai_cost_reservations (user_id);
CREATE TABLE ce_ai_cost_events (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(80) NOT NULL,
    module VARCHAR(40) NOT NULL,
    model VARCHAR(80) NOT NULL,
    day_period VARCHAR(10) NOT NULL,
    month_period VARCHAR(7) NOT NULL,
    input_tokens BIGINT NOT NULL,
    cached_input_tokens BIGINT NOT NULL,
    output_tokens BIGINT NOT NULL,
    cost_micro_usd BIGINT NOT NULL,
    input_rate_cents INTEGER NOT NULL,
    cached_rate_cents INTEGER NOT NULL,
    output_rate_cents INTEGER NOT NULL,
    state VARCHAR(16) NOT NULL,
    created_at BIGINT NOT NULL
);
CREATE INDEX idx_ai_cost_events_day ON ce_ai_cost_events (day_period, created_at);
CREATE INDEX idx_ai_cost_events_user ON ce_ai_cost_events (user_id, month_period);
CREATE INDEX idx_ai_cost_events_module ON ce_ai_cost_events (module, month_period);
