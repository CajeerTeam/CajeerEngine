# Automatic Release Discovery — CajeerEngine 2.0.1

Production code periodically queries **CajeerTeam/CajeerEngine GitHub Releases** for complete published release artifacts. Detection is read-only: it cannot download or execute a ZIP, grant update permissions, or change the live installation.

## Deployment and configuration

1. Upgrade the running CajeerEngine source to **2.0.1** through the normal verified release process. This changed-files patch is **not** an installable signed dist package.
2. Keep the normal scheduler running on the server (`php bin/cajeer scheduler:run`) from your existing cron/systemd timer. The scheduler should be invoked at least once every hour (every minute is the usual setup). The task is not a daemon and does not run unless the scheduler is invoked.
3. Allow the scheduler's PHP user HTTPS access to `api.github.com` and write access to `storage/app/updates/` and scheduler state. `ext-curl` and public DNS resolution are required for network checks.
4. Configure optional `.env` variables:

```dotenv
UPDATE_CHANNEL=stable
UPDATE_HTTP_TIMEOUT=5
UPDATE_AUTO_CHECK_ENABLED=true
UPDATE_AUTO_CHECK_INTERVAL_SECONDS=21600
```

`UPDATE_AUTO_CHECK_INTERVAL_SECONDS` is bounded to 3,600–604,800 seconds (one hour to seven days); by default a check is due every six hours. `false` disables scheduled checks but not the manual CLI command. Scheduler slot state is persisted and protected against duplicate execution using the existing `TaskRunStore` file or PostgreSQL lease.

Run `php bin/cajeer scheduler:due --json` to inspect tasks and `php bin/cajeer scheduler:run --json` to execute any due tasks. Run `php bin/cajeer update:check --json` to force a manual check. An update discovery task is named `updates.discover_releases`.

## Data and failure behavior

- The upstream request uses the GitHub Releases REST API and the configured channel; published complete `dist` artifacts are required. Automatic source ZIPs, drafts and incomplete uploads are not offered for installation.
- The first successful request creates `storage/app/updates/github-release-cache.json` with the normalized release catalog, ETag and timestamps. Later requests send `If-None-Match` if GitHub supplied a valid ETag. `304 Not Modified` preserves the catalog.
- If the upstream is offline, the cached catalog can still be displayed, but `metadata_status=stale` and `github_reachable=false`. If no cache exists, the original bundled/offline catalog may be shown with `metadata_status=fallback`. These statuses **do not** represent successful GitHub checks.
- When GitHub reports an API limit, `Retry-After` / `X-RateLimit-Reset` are used for a bounded cooldown. Checks do not follow redirects to arbitrary hosts, and a remote response is size-limited.
- Results are atomically persisted in `storage/app/updates/last-check.json`. The existing protected update diagnostics API reads this file and cached/bundled catalogs without initiating a network request. The scheduler state records success or failure; outages cannot be counted as success.
- These local discovery records are **not cryptographic trust material**. The separate signed ZIP updater remains responsible for validating `.sha256`, Ed25519 signature, publisher key, and exact artifact identity before applying any update.

## Verification

Run the offline deterministic regression `php tools/automatic-release-discovery-regression.php`, plus the existing `php tools/github-release-regression.php` and your full project CI. The new regression covers live logic with a controlled fake HTTPS transport, including status 200, 304, rate limits, invalid responses, downtime, normalization, and scheduling. A live API check on the deployment host and full signed-dist acceptance are separate operational gates.
