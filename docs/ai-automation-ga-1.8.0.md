# CajeerEngine 1.8.0 — AI Automation GA

The existing AI Background Automation runtime schedules actual OpenAI requests through `AiChat` and the managed queue. Version 1.8.0 closes delivery, privacy, and operations gaps identified in 1.7.9. The feature remains read-only from the CMS perspective: a generated result is stored in the owner's AI conversation, **not** published or used to modify CMS content automatically.

## Required upgrade sequence

1. Back up the CMS database and queues. Deploy 1.8.0 application code with the usual controlled upgrade.
2. Apply `migrations/<driver>/0043_ai_automation_ga.sql` after 0041 and 0042, using the normal migration runner. The migration creates indexes for bounded prompt retention and per-schedule cancellation. Schema is additive; no earlier automation rows are rewritten.
3. Set `AI_AUTOMATION_ENABLED=true` (or leave it unset). Configure a verified OpenAI model/credential and per-user/global budgets. Set the worker `QUEUE_JOB_TIMEOUT_SECONDS` to an operationally reasonable duration; calls interrupted after an ambiguous provider outcome are **not retried automatically**.
4. Keep the scheduler running at least once per minute and the worker processing the **default queue**, with the same DB and queue driver configuration. A missed scheduler window is not replayed as a burst.
5. Run `php tools/ai-automation-rabbitmq-regression.php`, `python3 tools/ai-automation-security-sqlite-regression.py` , `vendor/bin/phpunit --filter AiBackgroundAutomationTest --no-coverage`, and `AI_AUTOMATION_GA_FIXTURE=1 php tools/ai-automation-db-ga.php` (the last command only against isolated CI fixtures). The CI release job runs all four, including the real PHP/provider-mock execution tests.
6. Perform an operator acceptance run: create a 60-minute schedule, click **Run now**, verify an actual AI conversation and `succeeded` run status, confirm budget cost under `background_automation`, pause the schedule, verify no additional due execution, then re-enable it.

## Runtime guarantees and boundaries

- A run is committed to the DB before transport publication. A failed enqueue is recoverable through scheduler re-offers while the run is queued. Every offer carries the deterministic run-id key. An API response with `enqueued: true` means *offered*, not *executed*.
- A worker only accepts a job whose identity, queue, payload, and one-attempt limit match the committed run. It claims the `queued` row with an atomic compare-and-swap before contacting OpenAI. Messages arriving for an already-started, completed, canceled, or failed run are no-ops.
- The standalone RabbitMQ driver now preserves deterministic queue IDs and `_max_attempts=1`, with a regression check for failed publication. **RabbitMQ remains intentionally unavailable through `QueueFactory`** because its failed-job handling and broker durability are not certified. Deploy automation using one of the actually supported **file, Redis (with persistence) or PostgreSQL** queue drivers. The patched RabbitMQ driver is not a production enablement for RabbitMQ.
- The worker independently checks the **24-hour queue deadline** so an old message cannot run if the scheduler was unavailable first. A request already started when the worker or provider becomes unavailable might still be billed. There is no safe exactly-once or guaranteed-execution promise for external API calls.
- Scheduler failure to list dead-letter jobs does not prevent DB scheduling and pending enqueue offers. `dead_queue_scan_errors=1` and `ok=false` signal degraded transport introspection, while the run records remain recoverable. Queue enqueue failures are counted separately.
- `AI_AUTOMATION_ENABLED=false` (or `0`, `off`, `no`) immediately blocks new schedules/manual runs and prevents queued jobs from starting further OpenAI requests; the worker cancels such pending runs as they arrive. It does **not** revoke OpenAI requests that were already in flight. Restart the scheduler/worker processes after changing this environment variable; setting it back to true then allows due and pending work to proceed subject to the 24-hour deadline and standard budgets.
- Deleting a schedule is a soft delete for database traceability but immediately replaces its prompt and the run prompt snapshots with `[deleted]`, including snapshots in running rows. Existing AI conversations and backup copies remain subject to their own retention policies. Already running OpenAI calls cannot be forcibly canceled; after processing, the run retains only metadata.
- Completed run snapshots older than 30 days are redacted in **batches of at most 250 per scheduler pass**; this is not an immediate 30-day SLA during prolonged scheduler downtime. Existing AI conversation content is managed separately.

## Monitoring and operational acceptance

Inspect `ai.automation.dispatch` in scheduler task status. Key fields:

| Field | Meaning | Operator action |
| --- | --- | --- |
| `ok` | False if queue offers or dead-letter inspection failed | Check broker connectivity and scheduler logs |
| `enabled` | Actual emergency-switch state | Re-enable only after reviewing outstanding costs and workload |
| `created` / `offered` | Durable schedules claimed / queue offers made | Compare to recent expected schedules and pending count |
| `enqueue_errors` / `dead_queue_scan_errors` | Broker publication/inspection failures | Restore transport; rerun scheduler (no manual replay of running/failed jobs) |
| `interrupted` / `expired_queued` / `dead_queue_jobs` | Terminalized uncertainty or delivery failures | Inspect run history; never retry a possibly charged generation implicitly |
| `redacted` | Copies of expired snapshots redacted in this tick | Verify backlog of expired snapshots drains over multiple ticks |
| `backlog.queued`, `backlog.running`, `backlog.oldest_queued_age_seconds` | Current durable queue state | Investigate sustained growth or queued ages near 86,400 seconds |

Suggested acceptance cases: revoke an administrator's permissions after queuing, disable the owner, delete a queued schedule, fail the broker before enqueue, duplicate the broker delivery, leave a job queued for 24 hours, and run a provider failure containing a fake credential. In each case verify no duplicate paid request, generic/redacted error text, and the correct terminal state. Release CI covers these cases with PHP services, a fake provider, the file queue, SQLite/PgSQL/MariaDB schema acceptance, and an isolated AMQP-protocol regression for the disabled RabbitMQ driver.

### Rollback and limitations

Rolling back the application without rolling back the migration is acceptable for code that ignores the new indexes. To return to 1.7.9, stop scheduler and workers, deploy the earlier code, and keep existing 0043 indexes; do not re-run earlier migrations. Deleted prompt text is intentionally irreversible from the live database. Restoring it would require an older backup, with corresponding privacy and data freshness consequences.

Production certification depends on running the CI test suite and the manual operator acceptance against the deployment's actual database driver, broker, and OpenAI configuration. This archive provides working code and tests, not an assertion that those external production systems were exercised inside this environment.
