# CajeerEngine 1.8.4 — Database & Compatibility

## Deployment

Requires PHP >=8.4.1 and the appropriate PDO extensions. Primary target: PostgreSQL 17+; optional MySQL 8.4+, MariaDB 10.11+ and local SQLite 3.40+. No migrations were added in 1.8.4, so prior production ledger checksums and applied SQL files remain unchanged. Back up the database and filesystem before upgrading.

Stop the scheduler and workers; deploy the changed files; restart the PHP processes with the new code; run `php bin/cajeer migrate:status` and `php bin/cajeer migrate`. A nonzero migration-status exit code now includes checksum drift or a missing already-applied migration file, not only a failed SQL migration. Never clear the migration ledger to bypass drift detection. Restore the original file or verified backup and investigate unexpected changes.

## MySQL / MariaDB interoperability

Historic MySQL migration files `0005_queue_search_webhooks.sql` and `0007_queue_runtime_columns.sql` include `ADD COLUMN IF NOT EXISTS`, understood by MariaDB but not by MySQL 8.4. The execution engine checks `information_schema.columns` for the connected database, omits already existing columns, and issues standard `ALTER TABLE ... ADD COLUMN` otherwise. It preserves the exact original SQL bytes and recorded SHA-256 checksums. MySQL migration DDL cannot be atomically rolled back; a failure keeps the failed marker, and an operator must inspect schema and restore the pre-migration backup before running `migrate:repair`. Only the named application database is queried; runtime mode does not need privileges on unrelated schemas.

PDO multi-statement execution is no longer required: statements are parsed with correct handling of quotes and comments and executed sequentially. Scripts containing stored procedures/triggers or PostgreSQL dollar-quoted procedural blocks are not supported by this executor; the current 1.8.4 migration collection contains none. The schema integrity gate and existing regression suite verify the published files.

## Release acceptance

- `php tools/database-compatibility-regression.php`: offline, runnable without DB drivers; covers all published SQL files, conditional columns, quoted strings, and migration-driver mismatch.
- `DB_COMPAT_FIXTURE=1 php tools/database-compatibility-integration.php`: **destructive disposable test schemas only**, with CI-specific PostgreSQL 17/18, MySQL 8.4 and MariaDB 10.11 service credentials. Creates and removes randomly named schemas/databases, runs every migration, verifies status and idempotence, and checks a committed-schema rollback.
- `vendor/bin/phpunit --no-coverage`: includes SQLite live migration, migration failure recovery and checksum-drift tests.
- `php tools/generate-api-sdk.php --check`, `php bin/cajeer api:lint --strict --json`, and the existing release audit: API/SDK compatibility and release integrity.

The release workflow provisions separate PostgreSQL 17, PostgreSQL 18, MariaDB 10.11 and MySQL 8.4 services, in addition to SQLite, and exercises all published migrations in randomly isolated schemas/databases. None of these server-backed jobs have been executed in the local development environment; the workflow must pass before declaring server-level compatibility certified.
