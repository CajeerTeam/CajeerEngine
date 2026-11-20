# CajeerEngine 1.9.8 — Full Platform E2E & Recovery

## Scope

This release tightens **actual backup and restore behavior**, not only checklists. SQLite backup verification now requires both `PRAGMA integrity_check` and `PRAGMA foreign_key_check`. These checks run through PDO or the configured Python SQLite fallback; a database can pass `integrity_check` while containing orphaned references, so both checks are needed.

After restoration, the live SQLite database is hashed and compared with `database_sha256` from the HMAC-authenticated recovery manifest. A mismatch leaves recovery incomplete and is treated as evidence of an active writer or corruption; do not reopen the site until reconciled. An off-host encrypted bundle remains mandatory for disaster recovery. Do not run a destructive restore while application processes may be writing the database or queue.

## Executable acceptance

Run from the repository root:

```sh
composer install --no-interaction --prefer-dist
bash tools/e2e-acceptance.sh
php tools/database-recovery-integrity-regression.php
php tools/disaster-recovery-regression.php
php tools/deployment-recovery-acceptance.php
```

`e2e-acceptance.sh` requires PDO SQLite and `vendor/autoload.php` and stops on any failure. It exercises CMS publication/preview/search, middleware/RBAC/CSRF, media, imports, the queue and AI, then OIDC session persistence, analytics provider data persisted through the queue, transactionally checkpointed Migration Center import, and an authenticated disaster recovery snapshot of database, content, uploads and queued tasks. External APIs in those deterministic CI tests are replaced by explicit test transports. They are **not** evidence of production OpenAI, Keycloak or analytics-provider connectivity.

`database-recovery-integrity-regression.php` is also usable on hosts without PDO SQLite if `BACKUP_SQLITE_PYTHON` points to an executable Python 3 with `sqlite3`. It creates an isolated site and real SQLite database. It deliberately introduces a foreign-key violation while keeping the database structurally sound, checks that backup verification rejects it, and restores the exact snapshot and affected file inventory. It never touches a configured production database or installed site.

For offsite operational drills, use the dedicated `backup:drill` CLI workflow described in `docs/operations-recovery-1.8.8.md`; ensure the backup key remains off host, confirm the source instance is quiesced, and record RTO/RPO. This regression does **not** validate MySQL/MariaDB live restore or the separately configured PostgreSQL scratch database restore.

## Release gates and recovery procedure

1. Require the full release CI, including PHPUnit/PHPStan, PDO-backed tests and the PostgreSQL recovery job. Refuse releases with missing dependencies or skipped required tests.
2. On a production-like staging instance, perform a real encrypted backup export to separately mounted storage. Save digest, snapshot timestamp and redacted operation evidence.
3. Stop HTTP writers, worker, scheduler and DB clients. Enable maintenance; verify the backup and rehearse the isolated restore before attempting the destructive restore.
4. Restore to a clean staging host, validate referential integrity and file checksum inventory, and re-run HTTP/authenticated CMS and queue acceptance. Record measured RTO and RPO.
5. Test failure and retry paths (interrupted job, credential rotation, Keycloak outage, analytics provider 429, OpenAI timeout) using authorized staging credentials. These cannot be certified by offline protocol tests alone.
6. Reopen traffic only after completed staging postchecks and operator signoff. A failed database or file verification means recovery has not completed.

The release is **not certified for production** until the official dist artifact, signing key, live dependency matrix, offsite recovery and production-host E2E are verified on the actual release bytes.
