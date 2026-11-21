# Admin Update Center — CajeerEngine 2.0.4

This release connects the existing signed downloader (2.0.2) and non-root one-shot installer (2.0.3) to both bundled production static Admin SPA (`/admin/#/updates`) and Nuxt source (`/updates`). The **administrator controls approval; no unattended installation** occurs.

## Prerequisites

1. Install the real signed production dist on an installed, single-node Linux host, with PHP >= 8.4.1, ext-sodium, ext-curl for GitHub downloads, and production Composer dependencies.
2. Configure `UPDATE_TRUSTED_PUBLIC_KEY_FILE=/absolute/path/outside/webroot/trusted-ed25519-public.key`. Do not distribute a trust key from a newly downloaded release as its own trust anchor.
3. Configure a proven database backup key and validated restore procedure. `UPDATE_WEB_UPGRADE_ENABLED=true` and `UPDATE_OPERATOR_ENABLED=true` must both be set in the operator and PHP-FPM environments.
4. Install and enable the dedicated, non-root systemd units from [Update Execution Service](update-execution-service-2.0.3.md). Ensure the configured account can write the CMS update/backup directories and replace managed application files. For root-managed deployments, first provision matching ownership/permissions; never use root for the service.
5. Admin session must have the necessary `*` mutation scope for queued actions and `updates:read` for status. Browser requests use the existing HttpOnly session, CSRF and recent authentication-assurance step-up (Identity Center). Do not grant a privileged update token to public clients.

## Browser workflow

1. Open Admin → **Обновления**. Existing `GET /api/v1/updates` reads cached discovery, currently staged signed package, bounded request history and last operator status; it does not trigger a network download.
2. Press **Проверить сейчас** (`POST /api/v1/updates/check`). This refreshes GitHub Release metadata and records the selected channel/version. An offline fallback is diagnostic only.
3. Select **Скачать и подготовить**. The browser submits `POST /api/v1/updates/download` with exactly `{"confirm":true,"version":"x.y.z"}`. The backend refuses a different version, stale catalog (>1 hour), missing explicit confirmation or disabled operator. A durable request is created, not a PHP-FPM background process.
4. The systemd timer picks the oldest valid confirmed request, downloads the canonical signed `dist.zip`, `.sha256`, `.sig`, verifies package, signature and manifest, then publishes it into the staged inbox. A failure is recorded as terminal `failed`; it must not be replayed by editing JSON.
5. After the stage appears, select **Установить обновление**, verify exact version and confirm. The browser submits staged ID and digest to `POST /api/v1/updates/apply`. The operator runs the existing locked `UpdateManager::runRequest()` and the installer creates a backup, enables maintenance, copies checked files, migrates, verifies and records the result.
6. UI polls every five seconds while open. `pending` does not mean installed; `completed` is the durable operator result. Inspect logs before retrying a failed request.

## Failure modes

- No signed package / trust key mismatch: download request fails without publishing a staged update.
- GitHub API unavailable: explicit check reports degraded metadata; download does not use stale/offline metadata as installation authorization.
- `operator_ready=true` means configuration enabled, **not** that the timer is actively running. Verify `systemctl status cajeerengine-update-operator.timer` and `journalctl -u cajeerengine-update-operator.service`.
- A `running` request after a kill, an active transaction marker, or a restore/deployment journal causes `recovery_required`; the operator will not replay or process later requests until reconciled.
- Backups and emergency rollback remain protected CLI procedures in 2.0.4; use [backup and rollback runbook](update-backup.md). The interactive post-commit rollback workflow and stronger restore acceptance belong to 2.0.5.

## Testing / deployment

Run `php tools/admin-update-center-regression.php`, `php tools/update-execution-service-regression.php`, `php tools/secure-release-downloader-regression.php`, `node --check admin/static/assets/admin-app.js`, `node admin/scripts/publish-admin-assets.mjs --check`, and the repository CI checks. Verify authorized and denied API calls in staging and perform an end-to-end signed dist update under production-equivalent file permissions before enabling the feature.

The changed-files patch archive is *not* a signed production dist and must not be installed using `update:apply`.
