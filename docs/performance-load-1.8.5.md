# CajeerEngine 1.8.5 — Performance & Load Testing

This release contains **runtime improvements**, not only benchmark scripts. The immutable database migration history and API contract have not changed.

## Runtime fixes

- **CMS search:** `CmsSearchEngine::databasePage` now runs count, facets and paged result queries in a `DatabaseManager::readSnapshot` MVCC transaction. Previously `FOR SHARE` (PostgreSQL) or `LOCK IN SHARE MODE` (MySQL) on `ce_public_route_state` made long-running searches block publication writers. The derived search index and publication state are updated atomically, so a read-only repeatable-read snapshot preserves the required consistency without that lock. SQLite transactions preserve a consistent snapshot as well. Nested caller-owned transactions retain the caller's isolation level.
- **Public HTML cache:** concurrent cache misses for the same URL are serialized using a per-path advisory `flock` lock and a second cache check after obtaining it. Unrelated URLs render independently. Renderer exceptions propagate and are never retried implicitly; cache I/O failures fall back to uncached rendering. Entries are still invalidated by template content, render code and context changes, capped at 2 MiB and bounded by `PUBLIC_RENDER_CACHE_TTL` (0–3600 seconds).
- **PostgreSQL job queue:** the worker no longer performs a potentially expensive expired-lease recovery scan before every successfully reserved job. Recovery is checked on first access, after 32 reservations, after one second, or immediately on an empty ready queue. Recovery failures are propagated; they are never silently classified as no jobs. This changes the timing of periodic lease recovery but not the reservation-token safety requirements.

## Repeatable local acceptance

Run after installing PHP 8.4 with PCNTL and PostgreSQL/SQLite PDO extensions as appropriate:

```sh
php tools/performance-load-regression.php
php tools/queue-throughput-regression.php
php tools/performance-search-integration.php
python3 tools/staging-load-regression.py
```

The cache acceptance launches **12 independent PHP processes**, proves one render for a simultaneous miss, and tests invalidation, expiration, exception propagation and cache-off mode. Queue query-count regression uses a strict in-memory PDO test double; it does not measure real PostgreSQL latency. The search integration applies all real SQLite migrations and verifies pagination, facets and consistent reads across an overlapping writer commit. It requires `pdo_sqlite` and exits nonzero if the driver is missing; it does **not** silently skip.

The HTTPS harness regression uses a generated local TLS certificate, a disposable concurrent fixture server, and explicit 2xx/503 success/failure gates. These tests are wired into both PR CI and release CI. Existing PostgreSQL and Redis/queue integration tests remain mandatory in release CI.

## Real staging load test

**Use only a disposable staging installation with representative seeded data.** The runner performs GET requests only, uses verified HTTPS and must be explicitly opted into with `CE_LOAD_DISPOSABLE=yes`. It never stores response bodies, headers, bearer tokens or personal data in reports. Routes should be pre-seeded, safe to fetch repeatedly and should not mutate state.

```sh
export CE_LOAD_DISPOSABLE=yes
python3 tools/staging-load.py \
  --base-url https://disposable-staging.example \
  --route /api/v1/health \
  --route / \
  --workers 16 --duration 120 --warmup 5 \
  --min-rps 20 --max-p95-ms 800 --max-errors 0 \
  --report /tmp/cajeerengine-1.8.5-load.json
```

For authenticated GETs, provide `--auth-env CE_STAGING_BEARER` and set that variable in the process environment, **not** in a command line argument. Optional `--ca /path/to/staging-ca.pem` configures a verified staging CA; TLS verification is never disabled. The runner fails with a nonzero exit code when thresholds fail and refuses to overwrite existing reports. See JSON fields `rps`, `p50_ms`, `p95_ms`, `p99_ms`, `errors` and per-route distributions. Warmup traffic is excluded. The performance budget in this example is a proposed staging SLO, not a measured result for a customer deployment.

Run separate profiles for cached public routes, uncached search, read-only editorial lists and queue throughput, and capture PostgreSQL `pg_stat_statements`, Redis metrics, FPM worker saturation and CPU/memory separately. Compare equivalent infrastructure, data volumes and warmup policies between 1.8.4 and 1.8.5. The local regression harness does **not** substitute for a 24–72 hour soak test or real staging load certification.

## Rollout

No migration is needed. Deploy code over **1.8.4**, restart PHP-FPM and queue workers so new classes are loaded, and retain existing `PUBLIC_RENDER_CACHE_TTL` values. Verify caches, search freshness, queue lease recovery and rollback on staging before a production cutover. The new cache path lock files live in `storage/cache/public/`; this directory must be writable by the PHP runtime. On a read-only cache directory rendering continues, but cache-miss deduplication is unavailable.
