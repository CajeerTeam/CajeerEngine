# CajeerEngine 1.8.3 — Runtime & Queue Resilience

## Upgrade from 1.8.2

Back up the application and all queue stores, stop and drain 1.8.2 workers and the scheduler before deploying the changed files. Do not run different versions of workers against the same queue during a rolling restart. No database migration is added in 1.8.3. Deploy the updated PHP files, source metadata, Admin SPA assets, SDK versions and CI files; restart worker and scheduler units. Keep the existing backend-specific durability and backup policy from [queue-scheduler.md](queue-scheduler.md).

## Failure semantics

- `QueueWorker::work()` returns `ok=false` and `backend_unavailable=true` when a reservation or diagnostic query cannot be completed. A reservation failure ends the current finite batch; remaining jobs stay in their durable backend until the next supervised batch. The API returns HTTP **503** for backend unavailability, with `Retry-After: 5`. A handler failure returns a failed-job count and a nonzero CLI exit, but is not by itself a transport outage.
- If the handler finishes but `complete()` cannot be confirmed, the worker reports an **unconfirmed acknowledgement** and exits that batch. It deliberately does *not* call `fail()` to requeue the successful handler. The reservation might already have been committed; the backend must be inspected before any manual retry. Expired reservations can still be recovered automatically by the queue driver, so handlers that perform externally visible actions **must remain idempotent**. The queue still does not guarantee exactly-once effects.
- If a handler throws, the worker attempts `fail()` with the backend's retry/backoff logic. A failure to persist the error is surfaced as an unconfirmed reservation; subsequent batches must be supervised rather than looped blindly.
- `SchedulerRunner` now isolates each built-in task. Storage failures in one task do not prevent unrelated due tasks from being attempted. Its overall `ok` is false when any task fails, and `schedule:run` exits nonzero (including `--json`). Scheduler HTTP execution returns **503** with a retry hint; ordinary already-run/busy slots remain successful skips.
- Scheduler task history stores a generic error and exception type, not exception messages or credentials. Corrupt individual task histories report `state.status=unavailable` on the diagnostics endpoint without preventing other task states from being returned.

## Operations and diagnostics

```sh
php bin/cajeer queue:diagnostics
php bin/cajeer queue:failed --queue=default
php bin/cajeer queue:work --queue=default --limit=10 --json
php bin/cajeer schedule:run --json
```

When the worker reports `backend_unavailable`, verify DB/Redis connectivity, permissions and persistent storage, then restart it under the supervisor; do not treat 503 as a successful execution. For uncertain acknowledgements, inspect the application-level idempotency receipt and completed side effects before forcing a retry. Check the dead-letter list and scheduler task histories for failures. A scheduler failure is not evidence that all tasks failed: review per-task `ok` and `skipped` fields.

## Acceptance

```sh
php tools/runtime-queue-resilience-regression.php
php tools/queue-regression.php
php tools/security-hardening-regression.php
php tools/ci-check.php --strict
php tools/build-release.php --dry-run --all --json
```

The fault-injection test runs real file queue reservations, disconnect simulations on pop, completion and diagnostics, a failing scheduler cleanup, corrupted task state and sensitive exception messages. CI also runs queue tests against real PostgreSQL and Redis services; these were not implied by the local file regression and must be verified in their respective environments. Verify HTTP 503 behavior with the deployed Symfony stack, and do a failure/restart drill before production rollout.
