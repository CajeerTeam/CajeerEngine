# CajeerEngine 2.0.6 — Update History & Notifications

## Operational behavior

- `UpdateResolver::check()` records one `update.available` event for each eligible new GitHub Release and configured channel, even if both the scheduler and an administrator run checks. Stale/offline catalog entries never trigger notifications.
- Approved web requests are recorded with actor, action, request ID and time before being returned. The non-root operator records execution start and completion/failure. `UpdateManager::finish()` records committed installs and verified rollbacks after writing the transaction report. On restart, `UpdateHistory::reconcile()` repairs missing events from durable request and last-transaction journals.
- `storage/app/updates/history/events/*.json` contains atomic, independent audit records and must be protected from the public web root and retained outside rollback snapshots (`BackupManager::OMIT` already covers `storage/app/updates`). This is an operational audit, not cryptographically tamper-proof WORM storage; forward copies to externally protected monitoring for compliance retention.
- `/api/v1/updates/history` requires `updates:read` and supports `before=<event-id>` pagination. `/api/v1/updates/notifications` shows only current-user read state; `POST /api/v1/updates/notifications/read` requires session CSRF plus `updates:read`.
- The `updates.notifications.dispatch` scheduler task runs every minute, resubmits historical events with stable event IDs into the existing durable webhook outbox. Retry after a crash cannot create a second webhook outbox entry. The normal `webhooks.dispatch_pending` scheduler and queue worker must be active for actual HTTP webhook delivery. Downstream clients must deduplicate the `X-CajeerEngine-Event-Id` header for at-least-once network deliveries.

## Required runtime configuration

1. Keep both `scheduler:run` (cron or existing scheduler infrastructure) and `queue:work` operating. A deployment with only the systemd UpdateOperator will record local events, but will not send outgoing webhooks.
2. Give both PHP-FPM and `cajeer-updater` accounts group write access to `storage/app/updates/history`, setgid directories and protected `0660` history files. Configure the installation account's umask and operator group accordingly. Do not make this directory publicly accessible.
3. In **Integrations → Webhooks**, register an HTTPS destination and subscribe to `update.available`, `update.downloaded`, `update.installed`, `update.failed`, `update.rolled_back`, `update.recovery_required` and `update.backup_created` (or the wildcard `*`). The existing Event API signs webhook payloads using per-endpoint credentials.
4. The admin panel displays notifications without needing a webhook endpoint. Browser read acknowledgements are stored per authenticated administrator, independent from webhook delivery.

## Acceptance and recovery

Run `php tools/update-history-notifications-regression.php` in CI. Verify a real Release check, administrative approval, operator transaction, history entry, two independent admin sessions, subscriber webhook and retry after a worker restart on staging. Failed webhook deliveries stay in the standard queue/dead-letter process; inspect the existing Webhooks deliveries dashboard. If history storage is unavailable, creation of a **new admin approval** fails closed (the request journal is removed). Already-committed operations remain recoverable from their original journals and will be reconciled on the next scheduler pass. Do not delete history during incident recovery. Keep backups of audit files on a separate protected volume for long-term retention.
