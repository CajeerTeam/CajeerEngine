# 1.5.9 — Identity Acceptance & Migration

This release upgrades the **running session registry and deployment workflow**, not Keycloak users. No new SQL table or route is needed. The existing migrations through `0030_authentication_assurance.sql` must have been applied. The supported databases are PostgreSQL 17+/18+, MySQL 8.4+/MariaDB 10.11+, and SQLite for local testing.

## What is migrated

`identity:sessions:migrate` finds **unrevoked `ce_api_tokens` named `admin-session`** with no `ce_user_sessions` record. It processes them in bounded batches (default 100 rows, up to 10 batches per invocation), safely retrying from the first remaining token on each invocation. Each token is handled transactionally; already-registered sessions are never overwritten. It does not issue a new cookie/token, change permission scopes, extend TTL, or infer external identity from matching emails, user links or subject. A valid old token becomes provider `legacy`, retaining `created_at`, `last_used_at` and `expires_at`, with blank user-agent/IP and no fabricated OIDC `sid`/`sub`. Only new verified OIDC logins have provider `skif4er`.

An orphaned browser token is revoked if its user is disabled/missing, its absolute TTL has passed, or its `last_used_at` (falling back to creation) exceeds `SESSION_IDLE_MINUTES`. The service does **not** revoke user API/integration credentials. Existing local and OIDC session registry entries are left intact. Revocation is intentionally irreversible without a database restore; a rollback cannot reactivate expired credentials.

The status/acceptance service also rejects any session that references a different token owner or a non-browser API token. The migration will not silently repair such corruption.

## Safe deployment order

1. **Take and verify a database backup** including `ce_api_tokens`, `ce_user_sessions`, `ce_oidc_identities`, logout replay and OIDC transaction tables. Back up `.env`, `storage/`, and existing version `1.5.8`. Record current outstanding browser sessions. Do not copy secrets into terminal logs or tickets.
2. Deploy the **changed-files ZIP over the complete 1.5.8 tree** preserving paths. Run `php bin/cajeer migrate`. Keep `IDENTITY_SESSION_STRICT=false` during rollout and while any old PHP workers are serving requests. Restart/reload all PHP workers. Verify `php bin/cajeer --version` (if Composer is installed) and `cat VERSION` show `1.5.9`.
3. Check migration size and integrity using `php bin/cajeer identity:sessions:status`. This is a read-only JSON command. Confirm `inconsistent_registry` is zero.
4. Perform a bounded write batch using `php bin/cajeer identity:sessions:migrate --yes --batch-size=100 --max-batches=10`. **Only after backup verification.** It obtains an exclusive operation lock, refuses concurrent updates/maintenance, and does not run through HTTP. Repeat until `remaining=0` and `complete=true`; old tokens can appear as a rolling deployment completes, so check again after the last old worker stops.
5. Run `php bin/cajeer identity:acceptance --require-oidc --probe-idp`. For offline/local-only deployments omit `--require-oidc --probe-idp`. Any failed check returns nonzero; the command never changes the database. The optional IdP probe validates pinned Keycloak discovery and HTTPS endpoint availability, but does not prove a real login/MFA flow.
6. Configure `IDENTITY_SESSION_STRICT=true` **for all PHP workers and CLI processes** (in the same configuration source that the running app uses), then reload all workers. Strict mode refuses *unregistered* browser credentials instead of silently re-registering them. It does not affect personal API tokens, does not change existing active local/SSO sessions, and does not map old sessions to SkiF4er ID.
7. Run `php bin/cajeer identity:acceptance --require-oidc --probe-idp --probe-site`. `--probe-site` requests `/api/v1/auth/oidc/status` at the configured `APP_URL` over verified HTTPS, without credentials or redirects. It checks the live `X-CajeerEngine-Version: 1.5.9` header and the live OIDC/strict configuration against the current CLI process. It detects a stale serving node when routed there; for a load-balanced deployment, probe each node or repeat through the load balancer.
8. In staging and then production, exercise **real** local login, SSO with a previously linked user, OIDC MFA step-up, admin link/unlink, device logout, Keycloak back-channel logout and personal API access. Confirm test accounts have no elevated CMS permissions and audit events are recorded. Ensure the registered callback URL is exactly `https://<CMS_HOST>/auth/oidc/callback` and the Keycloak logout URLs are correct.

## Operational commands

```bash
php bin/cajeer identity:sessions:status
php bin/cajeer identity:sessions:migrate --yes --batch-size=100 --max-batches=10
php bin/cajeer identity:acceptance --require-oidc --probe-idp --probe-site
php tools/identity-session-acceptance-integration.php --require-db
```

`--probe-idp` needs a valid confidential SkiF4er ID client, correct `APP_URL` and configured OIDC keyring; external network failures **fail** this check. `--probe-site` needs PHP cURL, HTTPS and the deployed version; do not substitute the IdP SDK URL for the CMS `APP_URL`. `identity:acceptance` checks the persistent session schema, registry ownership consistency, all currently unregistered browser tokens, and when enabled the local OIDC encryption key's authenticated-encryption roundtrip. A passing acceptance report does **not** test passwords or generate a production login. The required CI test exercises actual PDO session validation and migration.

## Rollback / failures

If a new authentication or strict-mode issue appears, first set `IDENTITY_SESSION_STRICT=false` in the actual runtime configuration and reload PHP workers. This restores the previous on-demand legacy registry compatibility without restoring revoked credentials. Verify `identity:acceptance` and local admin login again. If the release itself is rolled back to 1.5.8, **do not** leave strict mode enabled in only some workers. The session registry format is backward compatible; do not delete `legacy` rows or manually rewrite `skif4er` provider values. Restore the verified database backup only as an explicit recovery decision, recognizing that it can restore revoked credentials from the backup snapshot. Never disable CSRF, step-up, or TLS to force a failed acceptance check to pass.

## CI acceptance

`tools/identity-session-acceptance-integration.php --require-db` is wired to `.github/workflows/release.yml` and fails (rather than silently skipping) when Composer dependencies or PDO SQLite are missing. It exercises resuming partial migration, repeat safety, stale/disabled/expired token revocation, untouched API and OIDC credentials, exact timestamps, rejection and recovery in strict mode, corrupted registry detection, and acceptance before/after migration. Existing OIDC, step-up, admin Identity Center, session and security regressions continue to run in the release workflow.
