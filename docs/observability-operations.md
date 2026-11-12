# Observability & Operations — 1.2.3

## Deployment and access

- `GET /health/live`: minimal 200 alive, independent of database, Composer, installer and maintenance. It verifies the PHP front controller, not availability of the CMS.
- `GET /health/ready`: 200 ready or 503 not_ready. Checks installation, required PHP dependencies, storage writability, minimum free bytes, maintenance/activity lock, DB connectivity and core tables. The readiness response deliberately exposes no dependency details. Legacy `GET /api/v1/health` keeps its shape and now incorporates readiness. A reverse proxy must route these paths to `public/index.php`.
- `GET /metrics`: disabled (404) until a dedicated random token of at least 32 characters is configured; unauthorized requests receive 401. Use TLS and internal network restrictions. `HEAD` returns headers only. Never expose tokens through query strings.
- `GET /api/v1/system/operations`: authenticated `system:read` operation; admin System screen uses this sanitized diagnostic report. It checks DB, storage, queue, cache, search, scheduler, last verified backup, worker heartbeat and disk. Nonessential failures are degraded; they do not change public readiness by themselves. Explicit CLI report and admin report are fresh; Prometheus samples dependencies at most once per 15 seconds. During maintenance, probes avoid the database and show readiness 0. A concurrent diagnostics scrape may receive 503 instead of waiting for expensive probes.

Configure `.env`:

```dotenv
OBSERVABILITY_METRICS_TOKEN=<random 64 hex characters>
OBSERVABILITY_MIN_FREE_BYTES=104857600
OBSERVABILITY_BACKUP_MAX_AGE=86400
OBSERVABILITY_QUEUE_MAX_AGE=300
OBSERVABILITY_WORKER_MAX_AGE=300
OBSERVABILITY_LOG_MAX_BYTES=10485760
```

Generate token with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Keep the secret in protected `.env` or process environment and Prometheus credentials_file, not in shell history or repository. Configure `resources/observability/prometheus-scrape.example.yml` and `prometheus-rules.yml`; integrate Alertmanager separately. Example alerts are starting thresholds, tune to your SLA. PHP-FPM and CLI workers must have access to the same `storage/logs/observability` directory. Run backup/update and web processes under the same application user or provision a shared group, setgid directories and group-write permissions for telemetry before deployment. The storage directory must never be served by the web server.

## Commands

```bash
php bin/cajeer ops:check          # readiness, nonzero when not ready
php bin/cajeer ops:report         # detailed sanitized JSON; nonzero when not ready
php bin/cajeer ops:metrics        # Prometheus format without HTTP authentication
php bin/cajeer ops:traces:flush   # optional OTLP exporter, one bounded batch
php bin/cajeer queue:diagnostics
php bin/cajeer queue:failed --queue=default
php bin/cajeer schedule:due
php bin/cajeer backup:verify --id=<backup-id>
```

`ops:report` reports optional failures in alerts even when readiness is true. It is available in maintenance and does not invoke migrations or recovery. Local probes write/read/remove a random test object; the S3 probe does the same against the configured storage disk. SQLite probes do not open a missing database. Do not use scrape endpoints as full load tests: dependencies can be slow and file queue diagnostics scan queue records. Configure timeout and sampling interval to match the deployment.

## Metrics

Cross-process histograms use fixed route names and status classes, not actual paths, tokens, query strings, job IDs or arbitrary extension names. SQL query/exec/prepared execute, connection and transaction timings are measured in the PDO runtime; SQL text and parameters are never recorded. High-frequency DB observations are buffered in batches of 128, flushed on shutdown/snapshot. HTTP/job/task timings are written immediately to 16 lock-protected shards under `storage/logs/observability`. Telemetry is best effort: a hard process kill can lose the last buffered SQL observations, never the application transaction. Counters persist across PHP processes and restarts; removing the metrics directory resets them. This is a single-node collector, not a distributed counter system.

- `cajeer_http_requests_total{route,status_class}`
- `cajeer_{http,job,scheduler,database}_duration_seconds{operation,outcome}` histogram (`_bucket`, `_sum`, `_count`)
- `cajeer_ready`, `cajeer_dependency_up{component}`
- `cajeer_queue_jobs{state}`, `cajeer_queue_oldest_ready_age_seconds` (excludes not-yet-ready jobs)
- `cajeer_scheduler_last_success_timestamp_seconds{task}`
- `cajeer_backup_last_success_timestamp_seconds`, `cajeer_worker_last_seen_timestamp_seconds`, `cajeer_update_last_success_timestamp_seconds`
- `cajeer_disk_free_bytes`, `cajeer_disk_total_bytes`, optional `cajeer_trace_spool_pending`

Backup timestamp is emitted after authenticated verification of a newly captured backup. It is not proof that a remote backup still exists or can be restored; run independent verify/restore exercises. Worker timestamp represents last batch/handled task, not a proof that every worker instance is alive. The UI p95 bucket is an upper bound from accumulated histogram observations; use PromQL `histogram_quantile(0.95, sum by (le)(rate(cajeer_http_duration_seconds_bucket[5m])))` for recent data.

## Logs and tracing

`storage/logs/runtime.jsonl` is structured JSON with request ID, trace/span IDs, route, HTTP status, duration, job ID and task outcome. Runtime logger keeps only an explicit metadata allowlist and known messages, no arbitrary payloads, headers, passwords, SQL parameters or exception messages. It rotates at the configured size (minimum 64 KiB), retaining five previous files. Older pre-upgrade logs, audit logs, explicit `log.message` jobs, external web server logs and extension-specific logs are separate; configure retention and secret handling for them independently.

HTTP recognizes a valid W3C `traceparent`, returns its own child span and logs correlation IDs. Optional OpenTelemetry-compatible OTLP/HTTP JSON export:

```dotenv
OBSERVABILITY_OTLP_ENDPOINT=https://collector.example.internal/v1/traces
OBSERVABILITY_OTLP_TOKEN=
OBSERVABILITY_TRACE_SAMPLE_RATE=0.1
```

Sampling respects a valid incoming parent sampled bit; otherwise it uses deterministic trace-ID sampling. HTTP, worker, scheduler and DB transaction spans have bounded names, timestamps, status and parent context. Queue jobs start their own trace; arbitrary message propagation between producers and consumers is not implemented. Collector is operator-configured and must be HTTPS (HTTP permitted only for local loopback), with redirects disabled, bounded response and 5-second I/O timeout. Local spool holds up to 1000 sampled spans, flushes at most 100 per minute via scheduler or `ops:traces:flush`. Failed or partially rejected batches remain for retry (duplicates can occur); when full, new trace samples are dropped to protect business operations. No external collector, tracing SDK dependency or network transmission is required by default.

## Runbook

- Readiness 503: check maintenance/recovery, installed.lock, database permissions/schema, storage and disk; do not restart endlessly on a deliberate maintenance window.
- Oldest queue age: inspect failed jobs and worker systemd status; `queue:recover` reclaims expired reservations. Do not retry externally visible jobs blindly.
- Scheduler stale: check last successful UTC interval and systemd timer, not merely the PHP process.
- Backup stale: create and verify a new backup using the configured key; copy off-host and periodically restore on a disposable host.
- Collector failure: inspect `ops:traces:flush`, TLS/token/endpoint; keep spool bounded.
- 5xx increase: correlate `X-Request-Id` and `traceparent` with runtime log; inspect DB query histogram and disk. Keep APP_DEBUG off in production.

## Validation

`php tools/observability-regression.php` exercises real HTTP probes without Composer, concurrent counters, rotating logs, actual file queue and scheduler, storage probes and OTLP delivery/retry. `--database` additionally exercises measured PDO SQLite queries. Release smoke validates readiness success, RBAC and metrics on installed SQLite/PostgreSQL dist. Prometheus server, distributed storage, external Redis/S3/collector and production load need deployment-specific validation.
