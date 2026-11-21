# CajeerEngine 2.0.7 — Production Hardening / Update Operations

## Scope

The existing signed GitHub Release download, staged install, encrypted backup and
rollback paths remain authoritative. This patch hardens the **actual execution**
and filesystem operations rather than adding a second installation flow.

* `OperationFiles::dir()` refuses to create a file or directory underneath a
  symlinked ancestor. The check is repeated after each component's creation.
* `OperationLock::openLockFile()` uses exclusive first creation, retains an open
  descriptor, and compares `fstat()` with `lstat()` for a regular, one-link,
  non-world-writable lock. It is used by operations, child subprocesses, the
  operator timer, the release download and browser approval queues.
* `UpdateOperator::assertApproved()` is checked both when selecting a queued
  request and immediately inside `UpdateManager::runRequest()` before marking
  it running. Invalid confirmations, payload digests and action names are
  rejected before invoking backups, downloads, migrations or file replacement.
* After a queued operation returns, the operator reads the durable journal and
  reports success only if the request was committed as `completed`. Disagreement
  produces `recovery_required`; an ambiguous pending request is durably marked
  `running` to prevent replay.

## Automated fault-injection acceptance

Run as the dedicated **non-root Linux operator account** with PHP 8.4 and the
same PHP extensions as production:

```sh
php tools/update-production-hardening-regression.php
php tools/update-execution-service-regression.php
php tools/admin-update-center-regression.php
php tools/secure-release-downloader-regression.php
php tools/update-history-notifications-regression.php
php tools/update-regression.php
php tools/ci-check.php --strict
```

The first regression creates a disposable temp installation and checks that
symlinked ancestor directories do not redirect backup/update writes; symlink,
hard-link, and world-writable lock substitutions fail; shared/exclusive locks
exclude one another; unconfirmed and malformed requests are rejected by the
**real CLI** entrypoint with no update side effects; noncanonical GitHub URLs
fail; and a fake executor returning success without persisting completion is
quarantined instead of retried. CI and release workflows enforce this regression.

`update-regression.php` additionally exercises actual signed ZIP validation,
recovery backups, database state and rollback (requires SQLite support). Offline
fault injection does not replace a real GitHub CDN transfer or production
validation of an externally signed release.

## Deploy and rollback drill

1. Apply the changed files to a staging clone of 2.0.6, with a full external
   backup and isolated database. Do not copy only new class files without
   replacing the existing manager, operator and lock code together.
2. Verify `UPDATE_TRUSTED_PUBLIC_KEY_FILE` and `BACKUP_KEY_FILE` point to securely
   provisioned keys **outside the application tree**. Confirm the dedicated
   non-root operator has read/write access to required update and backup
   directories and no root privileges.
3. Run the above acceptance checks. Verify the existing systemd timer executes
   a confirmed download request and records success; stage and install a real
   signed 2.0.7 `dist.zip` in the test environment.
4. Inject network failure, an invalid detached signature, a bad SHA-256,
   insufficient disk capacity and a killed installer. In each case verify that
   the current version is intact or a protected `recovery_required` journal
   and maintenance marker remain. Confirm no second automatic installation is
   attempted until reconciliation.
5. Execute an explicit supervised rollback on staging; compare application
   version, file hashes, database contents and request/audit history. Repeat
   with each production database engine, storage/queue backend and deployment
   topology; this patch does not claim those drills have been run.

## Incident handling

If the operator reports `recovery_required`, **disable its timer**, preserve
`storage/app/updates`, `storage/app/update-requests` and off-host backups,
inspect the journal and child process lease, and use the existing recovery
commands only after determining which writes occurred. Do not manually change
`running` back to `pending`: doing so could repeat a destructive update.
If a symlink/unsafe lock error occurs, inspect permissions and directory
ownership instead of disabling the validation. Never execute an updater as root.

The existing manual signed package verification, backup and rollback CLI
paths remain available to the authorized operator. Real Linux staging and
complete required CI (including Composer, extensions and database drivers)
must pass before declaring the 2.0.7 release production-certified.
