# Deployment & Recovery Acceptance — 1.8.7

## Supported installation and prerequisites

Run production operations from a trusted CLI/operator account, not the PHP-FPM identity. Use PHP 8.4.1+, ext-sodium, a matching database client, `flock` with `--no-fork` (util-linux), and a durable POSIX filesystem with directory fsync support. Automated file+DB restore supports local storage and file/PostgreSQL queues; external storage and Redis/RabbitMQ infrastructure need an independently consistent recovery plan. For PostgreSQL make sure the operator can create and restore the configured database; for MySQL/MariaDB rehearse restore on an isolated server and set `BACKUP_MYSQL_RESTORE_CONFIRMED=true` only after verification. Confirm no external writers are active and keep workers shut down for restoration.

Use different protected files outside the installation for the publisher's trusted Ed25519 public key and backup key:

```bash
export UPDATE_TRUSTED_PUBLIC_KEY_FILE=/secure/release.pub
export BACKUP_KEY_FILE=/secure/cajeer-backup.key
export BACKUP_COMMAND_TIMEOUT_SECONDS=3600
php bin/cajeer backup:create
php bin/cajeer backup:list
php bin/cajeer backup:verify --id=YYYYMMDD-HHMMSS-0123456789abcdef
```

`BACKUP_COMMAND_TIMEOUT_SECONDS` accepts 1–86400. A timeout fails the operation and preserves its journal/maintenance guard. Termination cannot guarantee external server rollback of a partially executed SQL statement: reconcile DB state and the restore journal before retrying. Choose a value appropriate to the largest production database and available I/O throughput. Database restore verification checks live SQLite integrity or target database connectivity for PostgreSQL/MySQL. It does not guarantee that external services or background jobs are healthy; verify those separately.

## Signed upgrade and rollback

1. Take an off-host encrypted backup, preserve the backup key separately, and validate it with an isolated drill. Stage the correct signed `dist` artifact and its detached signature (and SHA-256 from a separately authenticated channel).
2. Stop external writers and drain workers. Check deployment permissions, disk space, DB privileges, migration history and readiness.
3. Run `php bin/cajeer update:apply --package=/release/next-dist.zip --dry-run`. Planning a local signed ZIP does not depend on the availability of the remote release catalog.
4. Run `php bin/cajeer update:apply --package=/release/next-dist.zip` under the operator account. The updater enters maintenance, creates and verifies a full recovery set, journals the transaction, replaces the dist files, runs migrations and verifies health before reopening.
5. After successful commit, check HTTP/FPM, login, queue workers, media, and application metrics. Keep the verified backup until the post-deployment observation window has passed.

For a failed update, the updater attempts an authenticated automatic recovery. If the journal remains active, leave maintenance enabled and use `php bin/cajeer update:rollback` after checking any child subprocess leases. For a committed update, `php bin/cajeer update:rollback --yes` **discards all data written after the snapshot**. Take a separate copy of new writes before rolling back. If the live CLI cannot boot, use the authenticated `recover.php` saved inside the transaction directory with `BACKUP_KEY_FILE` explicitly exported.

## Administrator requests and interruption

Browser-generated operations require explicit confirmation. As of 2.0.3, approved staged `apply` requests can execute via a separately provisioned non-root systemd timer (`UPDATE_OPERATOR_ENABLED=true`); without it, explicit CLI execution is still required. `update:run --id=<request-id>` first persists `running`, before starting any update, rollback or backup. Success becomes `completed`; ordinary failures become `failed`. A killed process leaves `running` and is **not replayed**. This prevents a second restoration or update from executing on an unknown first result. Do not edit the request to `pending`. Instead inspect `storage/app/updates/active.json`, the corresponding transaction journal and any backup-restore marker, reconcile or roll back the interrupted transaction, then submit a new operator request.

## Offsite disaster recovery

```bash
php bin/cajeer backup:export --id=<backup-id> --file=/secure/offsite/site.cebackup
php bin/cajeer backup:drill --file=/secure/offsite/site.cebackup --workspace=/secure/drill-unique --rto-seconds=300 --rpo-seconds=86400
# On replacement host with original database identity, matching installation path and stopped services:
php bin/cajeer backup:import --file=/secure/offsite/site.cebackup
php bin/cajeer backup:verify --id=<backup-id>
php bin/cajeer backup:restore --id=<backup-id> --yes
php tools/update-postcheck.php verify
# Only after HTTP, workers, storage, login, and monitoring are manually checked:
php bin/cajeer maintenance:off
```

The encrypted bundle is not itself proof of service RTO or RPO. The isolated drill verifies extracted data/database while the full dist smoke restores live endpoints and login. Database dumps stored **locally** inside production backup directories remain plaintext; restrict operator access. Never store the backup key inside the installation or in an unprotected report.

## Acceptance gates

- `php tools/deployment-recovery-acceptance.php`: real SQLite database and authenticated backup, encrypted off-host drill, restore file verification, terminal operator requests, timeout/replay/failure test.
- `php tools/disaster-recovery-regression.php`: corrupt/truncated archive, wrong key, cold recovery after losing the site, media/environment/queue restoration.
- `php tools/update-regression.php`: real signed ZIP; synthetic new dist for transaction failure injection; commit/rollback and emergency recovery launcher.
- `bash tools/update-dist-smoke.sh /path/physical-dist.zip` and `bash tools/disaster-recovery-dist-smoke.sh /path/physical-dist.zip`: full Composer-equipped dist, real install/HTTP/migrations, postcheck and cold recovery. These require PDO SQLite, release dependencies and a functional runtime.
- PostgreSQL/MariaDB CI and operator-controlled offsite drills: verify privileges, restore into an isolated environment, real application readiness and operator RTO/RPO.

Mark release acceptance **incomplete** if any mandatory check did not run, even if syntax and SQLite standalones pass.
