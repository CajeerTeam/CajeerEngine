# Performance & Database — 1.2.1

## Upgrade from 1.2.0

Create and verify a backup before applying the patch. Use a maintenance window for the new indexes, particularly on a large PostgreSQL/MySQL table. They add write/storage cost in exchange for ordered, bounded reads. Existing migration files are unchanged.

```sh
php bin/cajeer backup:create
# Apply 1.2.1 files, then:
php bin/cajeer migrate
php bin/cajeer routing:check --json
php bin/cajeer search:check --json
php bin/cajeer api:lint --strict --json
php bin/cajeer admin:assets --check
```

If an existing PostgreSQL migration/index build needs longer than the default 30-second SQL timeout, configure an appropriate maintenance-session `DB_STATEMENT_TIMEOUT_MS` (0 disables it), then restore normal runtime limits. Do not run migrations concurrently with an updater/restore. The migration ledger is serialized using PostgreSQL advisory locks, MySQL named locks or a lock file next to the persistent SQLite database. All processes must use the same actual database and filesystem path; SQLite is single-node only. The lock wait is bounded at 30 seconds. MySQL DDL is not transactional: a durable failed/in-progress marker prevents an automatic retry after failure or interruption. Inspect the schema and restore the verified pre-migration backup before using `migrate:repair`; do not clear the marker blindly.

## Storage profile

`CONTENT_STORAGE=database`, `CMS_STORAGE=database`, `MEDIA_STORAGE=database` is the recommended production profile. Migrate legacy file content/media explicitly before switching. In production, `auto` resolves to database for content/CMS; a missing schema or failed connection is an error (HTTP 503), not a switch to another datastore. Local/demo use `APP_ENV=local` and explicit `file` storage where intended. Legacy local `auto` is retained, but a site with authoritative SQL data should always use explicit database storage. File JSON writes use durable atomic replacement; an existing empty/corrupt JSON file raises an error rather than silently returning an empty object. This is not a substitute for the backup/recovery mechanism.

New installations set content and CMS storage to database. Existing production installations intentionally using file storage must explicitly set both variables to `file` before upgrade, or migrate first. Do not mix SQL content with file CMS configuration in a distributed deployment.

## Connection limits

| Variable | Default | Applies to |
|---|---:|---|
| `DB_CONNECT_TIMEOUT` | 5 s | generated PostgreSQL DSN, MySQL connect |
| `DB_STATEMENT_TIMEOUT_MS` | 30000 | PostgreSQL |
| `DB_LOCK_TIMEOUT_MS` | 5000 | PostgreSQL |
| `DB_IDLE_TRANSACTION_TIMEOUT_MS` | 60000 | PostgreSQL |
| `DB_MYSQL_LOCK_TIMEOUT_SECONDS` | 5 | MySQL/MariaDB InnoDB row locks |
| `DB_SQLITE_BUSY_TIMEOUT_MS` | 5000 | SQLite |
| `DB_SQLITE_WAL` | false | SQLite, opt-in for local persistent DB |

SQLite foreign keys are enabled on every managed connection. Before enabling WAL, use local disk, test backup/restore and ensure all processes use compatible permissions. Never delete live `-wal`/`-shm` files. Custom PostgreSQL DSNs must include their own connect timeout. Invalid numeric limits are rejected. No automatic retries of arbitrary write transactions: callers must explicitly retry safe/idempotent operations after a transient failure.

## Editorial reads

`GET /api/v1/content/{type}?limit=50&offset=0&status=published&locale=ru&q=...` returns an actual bounded page plus `meta.total`, `meta.limit`, and `meta.offset`. `limit` is 1–200, `offset` is 0–1,000,000. Count and rows share a read snapshot, ordered by updated_at DESC, id DESC. The legacy unpaged call without limit/offset remains for compatibility; use paginated calls in integrations. Offset paging is not a cross-request immutable snapshot: concurrent edits can move rows between pages. Admin now uses 50-row pages and a bounded parent search, and preserves the selected parent even if it is outside the current search results. SQL substring search still scans matching rows; use the public FTS search for large text search workloads.

New indexes `ce_entries_type_live_updated_idx`, `ce_entries_type_locale_live_updated_idx`, `ce_entries_type_status_live_updated_idx` support ordered editorial queries. Translation groups use the existing group index. The SQL page does not load revision histories. Full graph rebuilding uses a single entry cursor instead of per-content-type list queries. Navigation menus/items are fetched in two queries, without one item query per menu or a second full menu read.

## Editorial writes

Content update uses a database compare-and-swap predicate on the previous revision (including clients omitting expected_revision); stale expected_revision returns HTTP 409. The outer publication transaction serializes writers on the route generation row; nested transactions use savepoints. No callback retries or partial commits are introduced. SQL edits whose ID/type/slug/locale/status and parent/path/_path are unchanged update the affected route metadata and search document only. SEO noindex, title, canonical and translation-group changes are reflected in the projection. Publication/status changes, tree moves, slug/locale changes, schema/theme changes, and explicit rebuilds retain complete graph validation. File mode still rebuilds the graph. Bulk import continues to use a single outer mutation. Structural graph rebuilding is O(number of entries) and still loads the graph in memory; this release is not a multi-node HA or large-scale mass-write certification.

## Reproducible testing

```sh
php tools/performance-regression.php
php tools/performance-regression.php --database # PDO SQLite
python3 tools/database-benchmark.py --rows 100000
# PostgreSQL: point DB_* at a disposable EMPTY database
CE_PERFORMANCE_PG_TEST=1 php tools/performance-regression.php --pgsql
```

The Python benchmark uses the actual SQLite migrations on a temporary database, compares the exact ordered SELECT before/after migration 0013, checks query plans for all three indexes, and verifies data and database integrity. Its timings are SQL-only, warmed cache, single-process and machine-dependent; not PHP/HTTP/production RPS. CI runs the PHP database tests and PostgreSQL test against a dedicated DB. No threshold based on machine-specific timings is imposed. Measure HTTP, memory, concurrent editors, cache and recovery separately before production certification.
