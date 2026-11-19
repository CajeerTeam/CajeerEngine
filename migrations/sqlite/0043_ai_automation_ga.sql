-- 1.8.0: Bounded retention scans and automation cancellation on busy installations.
CREATE INDEX idx_ai_automation_runs_retention ON ce_ai_automation_runs(status, finished_at);
CREATE INDEX idx_ai_automation_runs_pending_owner ON ce_ai_automation_runs(automation_id, status);
