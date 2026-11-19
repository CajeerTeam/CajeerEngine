# 1.7.9 — AI Automation Security & Acceptance

This release hardens the **existing, operational** AI Background Automation 1.7.8 implementation. It does not enable AI tools, content writes, unattended publishing, or bypass of the AI token/cost ledgers.

## Upgrade

1. Deploy the 1.7.9 changed files over 1.7.8, using the normal upgrade/backup procedure.
2. Apply `migrations/<driver>/0042_ai_automation_delivery_acceptance.sql` after migration 0041. The new nullable `last_offer_at` is necessary for the scheduler. Do not run new scheduler workers against the old schema.
3. Run `php vendor/bin/phpunit --filter AiBackgroundAutomationTest` with `pdo_sqlite` installed and `python3 tools/ai-automation-security-sqlite-regression.py`. Both checks also run in the release CI pipeline.
4. Start/restart the scheduler and queue worker with identical queue configuration; watch scheduler result `ai.automation.dispatch` for `ok`, `enqueue_errors`, `dead_queue_jobs`, `interrupted` and `expired_queued`.

## Authorization and concurrency

- All scheduling and manual execution decisions check active-account state and current owner permissions. Scheduled deliveries are rejected if the schedule is paused. Manual executions explicitly created while paused still work; pausing cancels already queued runs.
- PostgreSQL/MySQL hold a row-level lock on the *owner* for concurrent automation creation and manual/scheduled 24-hour quota decisions. These locks are within the existing database transaction. The 20 active schedules/owner and 100 created runs/owner/24 hours limits are not subject to concurrent read/check/insert races.
- Queue claims use a conditional status transition. An explicitly retried or duplicated queue message cannot start a second paid OpenAI request when a run is already running, succeeded, failed or canceled.

## Failure handling and at-most-once semantics

- If the manual run DB commit succeeds but transport push fails, the API returns `status: queued, enqueued: false` (HTTP 202). The scheduler later attempts delivery with the same queue idempotency key. The operator must monitor the queue; an enqueue attempt is **not** execution confirmation.
- Before making offers, the scheduler reconciles failed queue jobs **only when the job ID matches the deterministic idempotency key**. Runs still queued are marked failed; no implicit provider retries occur.
- `last_offer_at` gives every pending delivery a chance after the oldest batch is attempted. This avoids a permanently stuck group of 100 queued rows starving newly created deliveries. Failed offers are retried by the next scheduler passes while within the 24-hour delivery window.
- A queued run waiting longer than **24 hours** is failed instead of being executed long after its scheduled time. Worker failures after a billable call are never automatically repeated. Running jobs not completed after **30 minutes** are flagged as interrupted; a late successful provider completion can attach its original conversation and change the status to succeeded without another provider call.
- A lost queue acknowledgment after OpenAI has returned no longer changes the durable success record into an orphan. The existing `AiChat` service still owns AI reservation/settlement and billing.
- Worker queue error strings contain a generic message; provider exception details and sensitive prompt content are not stored in failed-job errors. Run statuses remain generic.
- Finished run prompt snapshots are overwritten with `[expired]` after 30 days. The original schedule prompt remains until that schedule is deleted; AI conversations retain their own content under the existing chat retention rules.

## Acceptance and limitations

The SQLite acceptance harness validates migration compatibility, unique scheduled slots, foreign-key isolation, delivery offer ordering, terminal-state CAS, and redaction. PHPUnit exercises the **real** PHP implementation with fake OpenAI responses and durable file queue: owner isolation, dispatch, budget settlement, permission revocation, queue outages/dead letters, expired deliveries, redacted failures, duplicate jobs, and fairness.

**Important:** The guarantee is at-most-once application-initiated provider calls per run, **not** exactly-once execution, not guaranteed delivery, and not proof that the provider did not charge for an interrupted request. If the queue worker or the scheduler is down, runs can expire without execution. Redis must be configured with persistence when used as the queue transport. Monitor application logs and spend reports during outages and before restarting services.
