# CajeerEngine 2.1.0 — GitHub Auto-Updater GA: deployment and acceptance

## Scope and release boundary

This version integrates release discovery, verified downloading, approved installation, automatic backups, rollback, a persisted audit, and admin/webhook notifications. The updater is designed for **one Linux node**. It never applies releases solely because a new GitHub release appears: a privileged administrator must explicitly approve each installation. This source patch is **not** a signed distribution, and passing local regressions alone is not production acceptance.

## Production prerequisites

1. Deploy a verified, fully built production `dist` on a **staging** node with the exact supported PHP runtime and extensions, database, storage, web server, scheduler and queue worker.
2. Generate the Ed25519 publishing key out of band; keep the signing secret completely separate from the deployed server. Provision only `UPDATE_TRUSTED_PUBLIC_KEY_FILE` with a valid public key under an external protected path. Ensure `BACKUP_KEY_FILE` is backed up securely outside the application tree. Set `UPDATE_WEB_UPGRADE_ENABLED=true` and `UPDATE_OPERATOR_ENABLED=true` only when the operator is installed and tested.
3. Configure `/var/www/cajeerengine` to match the service and timer in `examples/systemd/`, including a separate non-root `cajeer-updater` account, ownership of code and protected state, least-privilege supplementary group access to the request directory, and write rights required for verified recovery. Do not grant the PHP-FPM account code installation rights. Validate permissions on the **real** host. The existing root-managed deployment preset is not automatically compatible.
4. Enable `cajeerengine-update-operator.timer` and ensure a successful poll occurs before trying to queue an update from the browser. A live `operator-status.json` must have been written within `UPDATE_OPERATOR_MAX_HEARTBEAT_AGE_SECONDS` (default 180 seconds; allowed 60–3600). An absent, malformed, future-dated or stale poll disables approval and surfaces a reason in the Admin Update Center. An active recovery marker overrides even a fresh heartbeat.
5. Run `scheduler:run` and the queue/webhook workers separately. Update notification persistence does not imply that an external webhook receiver has accepted delivery.

## End-to-end operator workflow

1. Log into Admin Update Center and confirm that both key status and **operator ready** are displayed. Use **Check GitHub**; the current release and latest eligible release must be shown with a fresh GitHub status.
2. Click **Download and stage**. The non-root timer consumes a one-time request, downloads canonical GitHub distribution assets, verifies the `.sha256` sidecar, checks the Ed25519 detached signature and ZIP manifest, and publishes the staged package only after successful validation.
3. After the request reaches `completed`, verify that the staged version is newer than the installed version. Click **Install** and confirm the action. The operator re-authenticates the package, checks extension compatibility, takes a verified encrypted backup, enters maintenance, applies files and migrations, runs postchecks and commits or performs automatic recovery.
4. Verify the installed `VERSION`, API/UI assets, update journal and history; verify the public site, administrator login and queue/worker functionality. Check release notification and the webhook outbox/delivery confirmation.
5. Test the explicit **Rollback** flow using a disposable restored staging snapshot. The operator must verify the exact committed transaction, authenticate its recovery set and restore the previous code and database. Rollback destroys writes made after the previous upgrade: obtain explicit approval and preserve off-host copies.

## Mandatory fault and recovery drills

- Run the GitHub discovery, secure download, operator, admin, history, hardening, GA liveness and full SQLite/update regression scripts. Run the latter on a supported host with the required DB adapters and under a non-root operator where specified.
- Make the timer unavailable: wait for heartbeat expiry. The admin UI and HTTP operation queue must refuse new work; resume the service and verify acceptance recovers without manually altering heartbeat records.
- Feed a malformed/stale approval, tampered SHA-256/Ed25519 asset, truncated download, symlinked lock/status file, full disk and interrupted installation. **None** may silently become an installable package or be replayed as a second installation.
- Test the exact application's PostgreSQL or MySQL/MariaDB migration and encrypted-backup restoration on staging as appropriate. Validate emergency recovery launcher independently of the currently deployed PHP code.
- Require all mandatory GitHub Actions checks green, no missing production Composer dependencies, a fresh reproducible signed distribution and matching release checksums before describing the release as production-certified.

## Diagnostics

`php bin/cajeer update:status` reports `operator_ready`, `operator_status.status`, `operator_status.reason`, `checked_at`, and the staged-install eligibility flag. `heartbeat_missing`, `heartbeat_stale`, `heartbeat_invalid`, `heartbeat_unreadable`, `operator_busy` and `recovery_marker_present` block HTTP approvals. These fields do not expose key material, filesystem paths or downloaded archive bytes. Use `systemctl status cajeerengine-update-operator.timer` and `journalctl -u cajeerengine-update-operator.service` for service troubleshooting. Do **not** manually write `operator-status.json` as a workaround. See `docs/update-production-hardening-2.0.7.md` and `docs/backup-rollback-integration-2.0.5.md` for recovery details.
