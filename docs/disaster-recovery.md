# Disaster Recovery — 1.2.4

Recovery sets are consistent, authenticated snapshots of the database, managed release files, local media/content, installed extensions, file queue and encrypted environment. The DR transport encrypts **all** snapshot data with libsodium XChaCha20-Poly1305 secretstream, not only `.env`. It authenticates each frame and requires the final frame; truncation, tampering, wrong keys, trailing data and path traversal are rejected. Files are streamed in 1 MiB chunks. Imported sets are verified with their original manifest MAC, file hashes and database checks before atomic publication. Existing backups are never overwritten.

## Prepare and operate

- Run as the trusted installation operator on Linux with PHP 8.4+, sodium, `flock` and the database backup tools. SQLite uses PDO or the existing explicitly configured Python fallback. PostgreSQL requires compatible `pg_dump`, `pg_restore`, `psql`, `createdb`, `dropdb` and a dedicated database owner with CREATEDB for drills. Use a dedicated database; do not run these commands with untrusted dumps or superuser credentials.
- Mount an off-host filesystem that supports atomic hard links and directory fsync, or export to a protected local spool and transfer the resulting file with your authenticated object storage/backup client. Keep copies across failure domains, with immutable retention and the key backed up **separately**. A file outside the project is not necessarily off-host. Restrict directories to the operator (0700); exported files are 0600. Use encrypted disks for temporary plaintext snapshots and workspaces.
- Backup uses the existing exclusive application lease. Stop external writers that do not participate in the lease. Only default local storage and supported file/database queues are covered; S3, external queues, external search services and infrastructure need independently coordinated recovery. The local backup remains plaintext except `.env`, as in 1.2.3. A bundle is encrypted in its entirety.

```sh
php bin/cajeer backup:keygen --key=/secure/cajeer.key
export BACKUP_KEY_FILE=/secure/cajeer.key
php bin/cajeer backup:create
php bin/cajeer backup:export --id=<backup-id> --file=/mnt/offsite/site.cebackup
php bin/cajeer backup:drill --file=/mnt/offsite/site.cebackup \
  --workspace=/secure/drill-unique --rto-seconds=300 --rpo-seconds=86400
```

The workspace must not exist. Use a new name for each drill. The command decrypts the external bundle into the private workspace, verifies the snapshot, restores and re-hashes every file, restores `.env`, restores the database and reads its tables. SQLite checks integrity and foreign keys; PostgreSQL restores into a randomly named `ce_dr_*` database without `--create` or `--clean` and reads all user tables. The command removes the scratch database and files on normal completion and exceptions. If the host is killed, manually remove the abandoned private workspace and **only** the identified scratch database after verifying no drill is running. PostgreSQL drills need capacity for a second database; run against a dedicated recovery instance with matching configured identity where possible. Do not allow network-capable extensions in a rehearsal environment without isolation. MySQL/MariaDB encrypted export/import and original-identity restore are supported by the existing backup driver; isolated automated drill is deliberately refused because its SQL includes database-level DROP/CREATE. Rehearse MySQL/MariaDB on an isolated instance with the same identity and use the cold restore procedure.

The drill returns a machine-readable report and nonzero exit status for a failed restore or exceeded optional budget. `restore_seconds` measures extraction, verification and data restoration; `snapshot_age_seconds` measures elapsed time since snapshot capture. These are a rehearsal duration and snapshot-age budget, not a guarantee of end-to-end service RTO or actual loss of writes. `application_boot_tested=false` explicitly distinguishes this data drill from a full application recovery. Archive reports externally; schedule create/export/drill with the existing system scheduler or cron and alert on nonzero status. Do not delete previous verified off-host copies solely because a newer export succeeded.

## Cold recovery after loss of the host

1. Provision a replacement host with the same project path, compatible release, UID/GID, database identity, runtime dependencies and filesystem permissions. Keep HTTP and all workers stopped. Restore external infrastructure separately. Install the original or compatible recovery-capable dist (do not run a fresh CMS install against recovered data).
2. Restore access to the key and bundle. On the replacement host set the original DB driver/path or database host/port/name and credentials through the environment or a minimal bootstrap `.env`; create the SQLite parent directory. For PostgreSQL/MySQL provision the original database server and database role; backup restore may replace the named database. The backup intentionally refuses a different identity. For the original SQLite path, the actual DB file need not exist. Use `STORAGE_DRIVER=local`, `STORAGE_LOCAL_PATH=storage/app` and the original supported queue configuration. Ensure shell environment values match the recovered `.env` before booting.
3. Import and verify. This does not modify live content or the database. Then restore with explicit confirmation. The original environment and code are restored, and the existing full migration/schema/doctor/HTTP postcheck must pass before the restore marker is removed. Maintenance remains enabled.

```sh
export BACKUP_KEY_FILE=/secure/cajeer.key
php bin/cajeer backup:import --file=/mnt/offsite/site.cebackup
php bin/cajeer backup:verify --id=<backup-id>
php bin/cajeer backup:restore --id=<backup-id> --yes
php tools/update-postcheck.php verify
# Check actual deployment, content, media, admin login, worker health and external dependencies.
php bin/cajeer maintenance:off
```

If restoration fails, keep maintenance enabled and retry the same backup ID after fixing the cause. A pending restore marker blocks reopening. Do not restore a compromised snapshot or use a key that may have leaked. Key loss makes encrypted recovery impossible. Keep an independently secured key recovery procedure and exercise it.

## Release validation

`php tools/disaster-recovery-regression.php` exercises real SQLite data, a multi-frame media file, empty files, encrypted environment, incorrect key, modified/truncated/appended bundles, no-overwrite, idempotent import, isolated drill and complete loss of the local installation. `--pgsql` runs on the explicitly disposable `cajeer_backup_test` database. The release workflow also runs `tools/disaster-recovery-dist-smoke.sh` against a Composer-equipped dist: it installs, publishes content via HTTP, exports a backup, destroys the entire installation, imports/restores, checks media and restarts the application to verify public content and admin login. These tests are release gates, not claims about an untested deployment. Run the full cold recovery on each production profile and measure service outage before accepting its RTO/RPO.
