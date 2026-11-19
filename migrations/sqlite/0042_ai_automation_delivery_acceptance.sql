-- 1.7.9: Fair queue re-offers and durable audit of transport attempts.
ALTER TABLE ce_ai_automation_runs ADD COLUMN last_offer_at BIGINT NULL;
CREATE INDEX idx_ai_automation_runs_delivery ON ce_ai_automation_runs(status, last_offer_at, queued_at);
