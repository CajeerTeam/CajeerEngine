# Queue & Scheduler Reliability — 1.2.2

## Upgrade from 1.2.1

Stop **all** old workers, scheduler processes and writers before changing queue code. Do not mix old and new reservation protocols. Back up the existing installation. PostgreSQL queue users run `php bin/cajeer migrate` to apply `migrations/pgsql/0014_queue_leases.sql`; legacy reserved jobs with no lease token become eligible after the configured lease duration. File queue keeps the original pending/processed/failed directory format and reconciles interrupted legacy terminal moves. Inspect conflicts rather than deleting queue records.

Redis users must independently snapshot the Redis database, stop all old producers/workers on every host, deploy the patch, and run:

```sh
php bin/cajeer queue:redis-migrate --yes
php bin/cajeer queue:diagnostics
```

The offline migration scans old `job:*` hashes, recovers orphaned reservations and makes old pending/processed records visible in the new indexes. The legacy pending list is drained atomically by the new worker. Do not change the prefix during migration. Use a dedicated Redis instance/database and unique prefix per installation. Standalone Redis 7.x with persistence (AOF and appropriate fsync policy), no eviction and backup is required. Lua transitions are not Redis Cluster compatible. The core backup/restore still rejects external Redis queues; use a separate coordinated snapshot or drain/migrate before updater operations.

RabbitMQ's legacy adapter is not a reliable retry/dead-letter implementation: production factory now rejects `rabbitmq`/`amqp` rather than silently losing messages. Drain/export existing messages with the old installation before switching backend. Unknown queue drivers fail closed. SQLite/MySQL installations use file or Redis queues; the SQL queue is PostgreSQL only.

## Configuration

```dotenv
QUEUE_DRIVER=pgsql
SCHEDULER_DRIVER=pgsql
QUEUE_LEASE_SECONDS=300
QUEUE_JOB_TIMEOUT_SECONDS=120
QUEUE_RETRY_BACKOFF=60
QUEUE_WORKER_LIMIT=10
```

Use `QUEUE_DRIVER=file` and `SCHEDULER_DRIVER=file` for single-node local installations. With PostgreSQL, `SCHEDULER_DRIVER=pgsql` stores run history in `ce_scheduler_runs` and uses session advisory locks on a dedicated connection. All scheduler instances must share the same database/schema. Keep the default scheduler file backend for other databases. Set NTP/time synchronization, make the job execution limit shorter than the lease, and keep outbound request timeouts below the job limit.

## Operations

```sh
php bin/cajeer queue:push log.message --payload='{"message":"hello","_idempotency_key":"hello-1","_max_attempts":3}'
php bin/cajeer queue:work --once
php bin/cajeer queue:work --queue=default --limit=10
php bin/cajeer queue:diagnostics
php bin/cajeer queue:failed --queue=default
php bin/cajeer queue:retry <uuid> --queue=default
php bin/cajeer queue:recover --queue=default --limit=100
php bin/cajeer scheduler:run
php bin/cajeer schedule:due
```

`_available_at` accepts an ISO timestamp for delayed enqueue. `_idempotency_key` (up to 256 bytes) is scoped to the queue; repeating the same name/payload returns the existing ID, differing payload is rejected. A processed job is retained seven days by default; after cleanup the key can be reused. Do not use the queue as a permanent business deduplication ledger. `_max_attempts` defaults to 3, capped at 100. Retry uses exponential backoff up to one hour; manual retry resets the attempt counter. Separate named queues isolate work; run a worker for each queue (there is no global priority scheduler). Payload limit is 1 MiB; store large payloads by reference.

`queue:work` is a finite batch and exits nonzero when a job fails. SIGTERM/SIGINT stops new reservations and allows the current handler to finish; SIGALRM provides a CLI execution timeout with pcntl. A blocked native call may not return immediately; use timeouts in the handler itself. A killed worker's reservation expires and is reclaimed. `heartbeat()` extends ownership only while the token is live; stale completions and failures are rejected. There is no promise of exactly-once side effects: a process can die after an external effect but before acknowledgement. Extension handlers can accept `JobContext` as their second parameter, call `heartbeat()` between bounded batches, and use `idempotencyKey()` (job UUID) in their own durable business ledger. Existing one-argument handlers still work. External services must implement deduplication. The CLI timeout is a fixed per-job limit, not extended by heartbeat.

Generate systemd services as an explicit unprivileged application user:

```sh
php bin/cajeer worker:install --user=www-data --group=www-data --workers=3 --print
php bin/cajeer worker:install --user=www-data --group=www-data --workers=3 --system --write
php bin/cajeer scheduler:install --user=www-data --group=www-data --system --write
sudo systemctl daemon-reload
sudo systemctl enable --now cajeerengine-worker.service cajeerengine-worker-2.service cajeerengine-worker-3.service cajeerengine-scheduler.timer
```

The supervisor restarts finite batches after five seconds; existing application activity locks allow backup/update between batches. Disable services before maintenance and re-enable after verification. Additional queue names require explicit worker units with `--queue=name`. For a different job timeout adjust `TimeoutStopSec` accordingly. Stop and disable surplus units when reducing worker count.

## Scheduler policy

Tasks start at most once per UTC minute/hour/day slot (including failed/interrupted attempts). Missed slots are skipped rather than replayed in bulk. An interrupted running slot is recorded in history when the next slot starts; the current slot is not automatically rerun. A lock remains held during each handler, preventing overlapping starts. `schedule:due` and the existing scheduler API expose state, last success and up to 50 preceding run records. File mode stores state under `storage/app/scheduler`. PostgreSQL mode uses DB server time and advisory locks; it does not make file-backed content, webhooks or application-wide maintenance locks distributed. Full multi-node HA is not certified. Database connection loss or a handler that ignores its own deadlines still requires idempotent effects.

## Webhooks

`POST /api/v1/events/dispatch` and `webhook:dispatch` now accept events into the durable outbox; HTTP 200 means queued, not delivered. The scheduler hands each selected recipient a stable `webhook.deliver` job. Outbox handoff failures retain pending events with backoff; delivery failures use queue retry/dead-letter. A successful local receipt is persisted by event+recipient before acknowledgement. HTTP requests include signed `event_id` in JSON plus `X-CajeerEngine-Event-Id` and `Idempotency-Key` headers; receiver deduplication is mandatory because a crash between HTTP success and receipt commit can cause repeat delivery. The existing public-only outbound client retains DNS/IP validation, no redirects and bounded response/time limits. Disabled/deleted webhooks are skipped. Manual webhook test remains synchronous. Keep the local receipt store and outbox in the same backup/restore plan as the queue. The local outbox is not a transactional SQL domain-event outbox, and is not a distributed registry; multi-host deployments need shared durable application data or separate delivery architecture. Receipt/history retention and log rotation are operational tasks; do not delete receipts while jobs may be retried.

## Verification

```sh
php tools/queue-regression.php
# With real test PostgreSQL / Redis instances, never production data:
CE_QUEUE_PG_DSN='pgsql:host=127.0.0.1;dbname=test' CE_QUEUE_PG_USER=test CE_QUEUE_PG_PASSWORD=... php tools/queue-regression.php --pgsql
CE_QUEUE_REDIS_HOST=127.0.0.1 php tools/queue-regression.php --redis
```

The local regression exercises eight competing processes, SIGKILL recovery, stale tokens, delayed retries, failed-job reset, crash-between-write-and-rename, persistent schedule slots, webhook handoff and receipt deduplication, SIGTERM drain and CLI timeouts. PostgreSQL and Redis integration tests use isolated test schema/prefix and are included in release CI. They require the corresponding PDO/Redis PHP extensions and services.
