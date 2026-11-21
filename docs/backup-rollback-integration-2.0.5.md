# CajeerEngine 2.0.5 — Backup & Rollback Integration

## Requirements and supported topology

This release extends the **single-node Linux** update operator. It does not implement live multi-node or distributed-database rollback. The operator runs under its existing dedicated non-root account, with file, DB and key access appropriate to that account. Leave `UPDATE_WEB_UPGRADE_ENABLED=false` and `UPDATE_OPERATOR_ENABLED=false` until service permissions and verified backups are configured.

Configure:

```dotenv
UPDATE_WEB_UPGRADE_ENABLED=true
UPDATE_OPERATOR_ENABLED=true
BACKUP_KEY_FILE=/etc/cajeerengine/backup.key
BACKUP_MIN_FREE_BYTES=134217728
```

`BACKUP_KEY_FILE` must be a protected, absolute, 0600 file outside the project. Back up this key off-host; losing it makes encrypted `.env` snapshots unrecoverable. `BACKUP_MIN_FREE_BYTES` is the minimum *local* headroom (default 128 MiB; valid values at least 8 MiB). The updater also estimates the snapshot, SQLite dump, staged payload and additional 25% reserve; the greater estimate is enforced **before maintenance**. The installed filesystem and backup root must be writable by the operator and cannot be arbitrary symlink paths. Keep signed release publisher keys outside the installation too.

The existing recovery safeguards reject unsupported external storage/queue configurations. PostgreSQL requires sufficiently privileged `psql`/`pg_restore` access and a pre-provisioned recovery role; MySQL/MariaDB requires prior disposable-database rehearsal and `BACKUP_MYSQL_RESTORE_CONFIRMED=true`. Local free-space checks cannot guarantee capacity on a *remote* SQL data volume: monitor and provision the database server independently. During automatic recovery, stop or fence clients writing directly to the database (including out-of-band workers).

## Workflow from the administration UI

1. Open `/admin/#/updates` (static production Admin UI) or `/updates` in the Nuxt UI, sign in as an authorized administrator, and complete fresh reauthentication.
2. Press **Create backup**. This submits `POST /api/v1/updates/backup` with `{"confirm":true}` and does not perform file I/O in PHP-FPM. The next operator timer invocation executes the full authenticated snapshot, including database, permitted files, and encrypted `.env`. The status and recent backup entries appear on the page.
3. For installation, select a GitHub Release, download/stage it, and approve install as in 2.0.4. The updater now validates recovery permissions, backup key and free space before maintenance, and verifies the just-created recovery set again before replacing *any* live code.
4. If the last update succeeded but must be undone, the page shows the exact committed transaction and previous version. Press **Rollback** and confirm the data-loss warning and the word `ОТКАТ`. This submits `POST /api/v1/updates/rollback` with `{"confirm":true,"transaction":"<exact id>"}`. The operator rechecks the committed journal, current version, complete snapshot MAC/checksums, DB dump and permissions, and free disk space before making changes.
5. Follow `operator_status`, recent request results and maintenance state until the rollback is complete. Verify health and business data after any recovery.

**Rollback is destructive.** All content, media, queue and database writes made after the original backup can be lost. Take an independent externally stored snapshot of post-update state first when possible; use a maintenance window and communicate the data-loss risk. The browser cannot select an arbitrary backup ID, filesystem path or command. Only the *latest committed transaction* can be rolled back through the UI. Older backups and interrupted transactions require the dedicated protected CLI recovery process.

## Operator and CLI

```bash
sudo systemctl status cajeerengine-update-operator.timer
sudo systemctl status cajeerengine-update-operator.service
php bin/cajeer update:status
php bin/cajeer backup:list
php bin/cajeer backup:verify --id=YYYYMMDD-HHMMSS-XXXXXXXXXXXXXXXX
```

Emergency recovery (authorized console only):

```bash
php bin/cajeer update:rollback --yes
php bin/cajeer backup:restore --id=YYYYMMDD-HHMMSS-XXXXXXXXXXXXXXXX --yes
```

An interrupted update creates `storage/app/updates/active.json`; interrupted standalone restore creates `storage/app/backup-restore.json`. **Do not delete either file or disable maintenance to bypass an error.** Inspect the transaction journal, verify the snapshot and use the already-copied `recover.php` in the transaction directory when the live PHP tree cannot load. Ensure surviving child processes release their leases before recovery.

## Backup verification and offsite copies

The authenticated backup manifest includes file SHA-256 inventory and a keyed HMAC; the database dump is validated, the encrypted environment is authenticated, and the restore rechecks copied files and database consistency. A record marked `complete` in the Admin UI means the capture finished; it does *not* replace a fresh cryptographic verification at restore time.

Protect recovery from disk loss with independently retained encrypted recovery bundles:

```bash
php bin/cajeer backup:export --id=YYYYMMDD-HHMMSS-XXXXXXXXXXXXXXXX --file=/mnt/offsite/cajeerengine-backup.bundle
php bin/cajeer backup:drill --file=/mnt/offsite/cajeerengine-backup.bundle --workspace=/mnt/isolated-rehearsal
```

The drill requires an isolated workspace. Rehearse actual restores to *disposable* DB servers for PostgreSQL and MySQL/MariaDB; never point a drill at production data. Recovery readiness in this release is a conservative preflight, **not** proof that all operational failures can be auto-repaired. Test filesystem, SQL and network fault scenarios in staging with the exact signed artifact before enabling on production.

## Regression and deployment acceptance

Run `php tools/update-regression.php` with working SQLite Python fallback or `pdo_sqlite`, `php tools/update-execution-service-regression.php` as a non-root user, `php tools/admin-update-center-regression.php`, `php bin/cajeer api:lint --strict --json` and `php tools/ci-check.php`. The update regression uses signed synthetic dist **only to inject failures**, and is not a substitute for publishing and installing a real signed distribution in staging. Check that a modified MAC blocks rollback before maintenance and does not alter the installed version.
