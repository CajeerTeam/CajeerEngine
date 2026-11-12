# Production Updater & Backup (1.1.4)

## Supported deployment

The updater applies a complete **signed dist ZIP** (release-manifest v5) to a single Linux node. It does not apply a source ZIP, a changed-files patch, an unsigned artifact, or an older/equal version. `--force` cannot bypass integrity or downgrade checks. Adopt 1.1.4 from 1.1.3 using the source patch with a maintenance window and a separately verified backup; the 1.1.3 updater is intentionally disabled. Thereafter use the commands below. Only install releases obtained from a trusted publisher.

Requirements: PHP 8.4+, sodium, PDO driver, proc_open, Phar, ext-zip or unzip, util-linux `flock`, a fully installed CMS with production Composer dependencies, sufficient space for the copied ZIP, extracted files and the complete previous release/data/DB. Use a local filesystem that supports flock and fsync. Stop external writers, cron jobs outside CajeerEngine, persistent workers and extension processes before operations. CajeerEngine HTTP and CLI entrypoints cooperate through a shared activity lease; the updater/backup takes an exclusive lease and refuses to proceed if it cannot acquire it. No multi-node coordination is implemented.

Default local storage, file or PostgreSQL queue are supported. External S3/custom storage and Redis/RabbitMQ queues are rejected for automatic recovery rather than silently omitted. Cache data is disposable; clear the dedicated external cache namespace and configure OPcache to revalidate on every request or reload PHP-FPM during the maintenance window. Workers must be restarted to load new code. The updater checks a fresh CLI PHP process, not the PHP-FPM process; test the live web endpoint after the operation.

### Database requirements

- PostgreSQL: matching `pg_dump`, `pg_restore`, `psql` on PATH. Dedicated CMS database; the configured role must own it and have CREATEDB (or an appropriately isolated administrative role). Restoration drops and recreates the database, connecting to `postgres`. No other sessions may remain connected during recovery. For a least-privilege runtime role, export separate operator credentials `BACKUP_DB_USERNAME` and `BACKUP_DB_PASSWORD` only in the backup/update process; the backup connection uses the configured DB host/name. The same overrides are supported for MySQL. Test restoration and privileges before using a valuable site; cluster roles/tablespaces outside the DB are not captured.
- MySQL/MariaDB: compatible `mysqldump` and `mysql` clients (or installed compatible aliases); dedicated database, permissions to drop/create and restore tables, triggers, routines, events. The dump is made with a consistent transaction; nontransactional tables require all writers stopped. Explicitly set `BACKUP_MYSQL_RESTORE_CONFIRMED=true` only after a successful rehearsal. MySQL/MariaDB support is not certified by the local regression run.
- SQLite: persistent database file; PDO `VACUUM INTO` gives a consistent snapshot. If PHP lacks `pdo_sqlite` during disaster recovery, optionally set `BACKUP_SQLITE_PYTHON=/usr/bin/python3` (Python with stdlib sqlite3). The Python path uses SQLite's backup API, not a raw copy of a WAL database. The CMS itself still requires its normal PHP extensions.

## Provision keys

On an offline or protected release host, generate an Ed25519 signing key pair (do not put the secret in Git, in a release, or on the web server):

```bash
php bin/cajeer release:keygen --secret-key=/secure/release.secret --public-key=/secure/release.pub
php bin/cajeer release:sign --package=/release/cajeerengine-1.1.4-dist.zip --secret-key=/secure/release.secret
```

Publish the ZIP, `.sha256`, `.sig`, and manifest. The signature covers the exact ZIP SHA256 using a domain-separated message. The manifest inside the archive is authenticated by that signature. Install the public key out of band, verify its fingerprint (`key_id`), and protect it against web writes. Sign only after release verification; `RELEASE_SIGNING_KEY_FILE` optionally enables signing in `tools/release-pipeline.sh`. No trusted publisher key or secret is shipped in the project. Key rotation requires distributing a new trusted public key through a separate trusted channel.

On the installation host, as the operational user:

```bash
php bin/cajeer backup:keygen --key=/secure/cajeer-backup.key
```

Set in the environment (or in the protected `.env`, except emergency launcher requires explicit export):

```dotenv
UPDATE_TRUSTED_PUBLIC_KEY_FILE=/secure/release.pub
BACKUP_KEY_FILE=/secure/cajeer-backup.key
UPDATE_WEB_UPGRADE_ENABLED=false
```

The backup key is private (0600); keep a protected off-host copy. Loss of the key makes authenticated restore impossible. `.env` is encrypted with sodium secretbox, and the inventory is authenticated with HMAC-SHA256. Database dumps and ordinary files are **not** encrypted by this feature. Keep recovery sets private and encrypt their off-host copies. Do not use the release signing key for backups.

Use a dedicated operator account with permission to replace release files and restore database/files with their original owner/group. PHP-FPM must not be able to replace application code or private recovery sets. Provision a shared group for the operation lock `storage/app/operations.lock` (0660), the `storage/app/update-requests` directory (group-writable, setgid) and the ordinary runtime paths. The `storage/app/updates` directory must be traversable by PHP-FPM so that it can detect `active.json`; transaction/inbox and backup subdirectories remain private to the operator. Review ownership after provisioning, and verify FPM/worker leases against the same inode. Do not delete or replace an operation lock during service. The operator and FPM must have separate privilege boundaries; keep backups and transaction state outside the document root. The updater rejects symbolic links in managed paths; deploy as a physical tree, not symlinked release/shared directories.

## Backup

```bash
php bin/cajeer backup:create
php bin/cajeer backup:list
php bin/cajeer backup:verify --id=YYYYMMDD-HHMMSS-0123456789abcdef
```

Each backup is a directory `storage/app/backups/production/<id>` containing the database dump, exact file inventory, data and managed code, encrypted environment and MAC. This includes local content/media/queue, user extensions and release files (including vendor). Excludes disposable caches/logs, other backups, operation state, and externally stored data. Verification checks every recorded file, rejects additions, verifies database dump format/integrity and decrypts the environment. Copy the complete directory and key to a secure off-host location. Keep at least the latest known-good release and backups until a newer backup has been restored successfully. Automatic remote retention/PITR is not included.

Restore is destructive and requires `--yes`. It verifies the complete snapshot before applying changes, restores the original database identity (not an arbitrary new target), removes files created after the snapshot and restores previous file permissions. It leaves maintenance on, including after success:

```bash
php bin/cajeer backup:restore --id=YYYYMMDD-HHMMSS-0123456789abcdef --yes
php tools/update-postcheck.php verify
# Verify actual HTTP/FPM and worker settings, then:
php bin/cajeer maintenance:off
```

A failed restore leaves a durable restore marker; retry the same ID after correcting the problem. `maintenance:off` refuses while a restore is pending. For complete server loss, provision the same DB identity, project path, compatible code and UID/GID, copy the authenticated backup and key, then restore. This is not a cross-site content import or an automatic provisioning tool.

## Update

```bash
php bin/cajeer update:verify-signature --package=/release/next-dist.zip
php bin/cajeer update:apply --package=/release/next-dist.zip --dry-run
php bin/cajeer update:apply --package=/release/next-dist.zip
php bin/cajeer update:status
```

Put the detached signature next to the ZIP as `next-dist.zip.sig` or specify `--signature=/path/file.sig`. An optional `--sha256=...` adds an independent expected digest. The dry run checks and extracts the package and prints the copy/removal plan but does not change live code or DB. It leaves private staging files for inspection. `--no-backup`, `--no-maintenance`, `--no-migrations`, `--no-doctor` are rejected. Apply takes an exclusive lease, checks the current installation, enters maintenance, creates and verifies a recovery set, records the journal, copies only managed files, removes explicitly retired files, files from the previous dist manifest that are no longer shipped, and obsolete vendor files, clears local caches, runs migrations and verifies DB/migration/HTTP health using a fresh process. No arbitrary deletion list is accepted. It reopens only on successful commit or verified automatic recovery, and preserves pre-existing maintenance.

### Browser workflow

1. Set `UPDATE_WEB_UPGRADE_ENABLED=true` only when needed.
2. Operator runs `php bin/cajeer update:stage --package=/release/next-dist.zip` to verify and stage the artifact.
3. Sign in at `/admin/` and open `/upgrade/`. Select the staged release/digest and confirm. The browser sends an authorized CSRF-protected request; API returns 202, not a claim that the update ran.
4. Operator inspects `php bin/cajeer update:requests` and executes `php bin/cajeer update:run --id=<request-id>`. The request is bound to the signed artifact. No privileged web daemon consumes requests automatically.
5. Check the report and site. Disable the web upgrade flag when finished. The Admin UI backup button similarly queues a request for the operator.

### Failure / rollback

If apply fails after files changed, the updater restores the pre-update database and files. If restoration also fails, the active journal stays in place and HTTP remains blocked. A killed child process retains a separate subprocess lease; recovery refuses until it exits. State lives under `storage/app/updates/transactions/<id>/journal.json`, with `active.json` and `last-update.json` summaries.

```bash
# Interrupted update; no confirmation flag needed while active:
php bin/cajeer update:rollback
# Completed update: explicitly discard all later writes:
php bin/cajeer update:rollback --yes
```

If the normal CLI cannot start due to mixed code/vendor, use the saved old-code launcher. It verifies the backup MAC and snapshot hashes before executing snapshot code:

```bash
export BACKUP_KEY_FILE=/secure/cajeer-backup.key
php storage/app/updates/transactions/<id>/recover.php
```

For an interruption before backup finished, no live files have changed; use normal rollback to clear the journal. If the recovery backup or key is unavailable, do not force maintenance off: restore an independently verified off-host snapshot. A committed rollback discards writes made after the update, including media and DB writes. Create a new backup first if those writes must be retained. Manually remove obsolete private transaction staging directories only after confirming no active transaction or dependent recovery set uses them.

## Tests and release gate

```bash
php tools/update-regression.php          # Ed25519, ZIP, actual SQLite, failure-injection dist fixture
php tools/security-regression.php
php tools/release-regression.php
php bin/cajeer api:lint --strict --json
bash tools/update-dist-smoke.sh /release/cajeerengine-1.1.4-dist.zip
```

The last test needs actual Composer dependencies and PDO. It installs a real dist in a disposable directory, adds a test migration to a signed newer dist, tests commit/rollback and a failing migration. The CI PostgreSQL round-trip uses `tools/backup-db-regression.php` with a specifically named disposable database. Do not set the release gate to PASS if these tests cannot execute. Use a staging copy of each actual production profile and verify HTTP/FPM, restore time and off-host backups before live deployment.

For encrypted off-host transport and isolated recovery drills, see [Disaster Recovery 1.2.4](disaster-recovery.md).
