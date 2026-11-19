# AI Background Automation — CajeerEngine 1.7.8

## What runs

Administrators with `content:read` and `content:write` can create up to 20 owner-scoped AI prompt schedules. A schedule can run every 60–10,080 minutes (1 hour to 7 days) and can also be executed immediately. Runs produce **actual OpenAI Responses** via the existing `AiChat::send` service (no tool calling or implicit write access); the AI response is stored in the owner's conversation history. The existing token reservation and USD-micro cost ledger enforce the per-user and global budgets **before** the provider request. Charges are attributed to `background_automation` in the AI cost report.

A new schedule's first automatic execution is one full interval after its creation. Pausing disables further automatic runs and cancels queued runs. Resuming starts a fresh interval. Editing the prompt or title does not move the next scheduled time. An in-progress OpenAI request cannot safely be canceled: pausing affects **queued** and future work only.

## Deployment

1. Apply migration `0041_ai_background_automation.sql` for the selected database (`pgsql`, `mysql`, or `sqlite`), using the normal CajeerEngine database migration command.
2. Configure the OpenAI API key, model and verified model pricing in AI settings; inspect daily/monthly cost caps.
3. Keep the existing **Scheduler** running at least once per minute and the **QueueWorker** processing the default queue. See `bin/cajeer` queue and scheduler commands or the existing service/cron installation.
4. Open **Admin → AI Assistant → AI Background Automation**. Create a schedule, run it now if needed, and refresh the run history. Completed runs link to ordinary AI conversations.

The scheduler only creates durable database run records and enqueues jobs. It does **not** call the provider in a web request. A stable queue idempotency key (`ai.automation.<run-id>`) permits recovery if the process crashes between the DB commit and queue push. A job claims the run atomically, so repeat deliveries do not repeat the provider call. Queue jobs allow only **one execution attempt**; after an unknown/failed provider outcome, automatic retries are blocked to avoid duplicate charges. Interrupted runs older than 30 minutes are marked failed and require an explicit new run. An OpenAI call might still have been charged before an interruption; inspect the cost report.

## API

Endpoints below require an active browser admin session (plus the listed route permissions and standard CSRF/middleware). They cannot be invoked with a bearer-only API token.

| Method | Route | Access |
| --- | --- | --- |
| GET | `/api/v1/ai/automations` | `content:read` |
| POST | `/api/v1/ai/automations` | `content:read`, `content:write` |
| GET | `/api/v1/ai/automations/{id}` | `content:read` |
| PATCH | `/api/v1/ai/automations/{id}` | `content:read`, `content:write` |
| DELETE | `/api/v1/ai/automations/{id}` | `content:read`, `content:write` |
| GET | `/api/v1/ai/automations/{id}/runs` | `content:read` |
| POST | `/api/v1/ai/automations/{id}/run` | `content:read`, `content:write` |

Create body example:

```json
{"title":"Daily report","prompt":"Create a concise daily report for the user.","interval_minutes":1440,"enabled":true}
```

`PATCH` accepts any subset of `title`, `prompt`, `interval_minutes`, and `enabled`, plus the **required current** integer `revision` (optimistic concurrency). Run-now requires `{}` as the JSON body. Only the owner can list, change, run, or inspect that schedule and its history. Deletion is a soft delete of the schedule; completed conversations remain accessible to the owner.

## Security and operational limits

- No autonomous content mutation, publishing, tool permissions, network scraping or CMS secrets in prompt context.
- Prompts and run snapshots are retained in the CMS database, with the normal CMS database access controls and backups. Only owner-scoped API routes expose them.
- Account deactivation cancels pending jobs. Removal of `content:read` or `content:write` authorization stops a due schedule and disables it; workers revalidate permission before the call.
- Per-automation pending-run guard and per-owner cap of 100 runs in a trailing 24-hour window. Scheduler processing is batched (50 due schedules, up to 100 pending queue offers per minute).
- Updates/deletes cannot preempt a running provider call. Provider failures store a generic redacted run status and a protected worker log contains diagnostic details. Periodic scheduling skips missed windows rather than backfilling.

Do not use this facility for high-frequency autonomous writes; AI responses are advisory and require human review. Monitor the QueueWorker, the scheduler run status (`ai.automation.dispatch`), and AI budgets in production.
