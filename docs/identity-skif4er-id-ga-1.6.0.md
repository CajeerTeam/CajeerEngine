# CajeerEngine 1.6.0 — Identity SkiF4er ID GA

## Real runtime behavior

- Browser sign-in uses a **confidential** Keycloak OIDC Authorization Code + PKCE S256 client, one-time server-side state/nonce, RS256 signature/issuer/audience verification, a matching explicit local subject binding and a revocable **opaque** `ce_session` cookie (Secure, HttpOnly, SameSite=Strict). No upstream tokens are written to browser storage, server responses or logs.
- User-initiated linking requires a live local browser session, correct local password and enabled TOTP, HTTPS and CSRF. The callback accepts the original live session in **both** the API token table and browser-session registry; revoked or expired sessions cannot complete linking. Matching emails never link accounts implicitly.
- A newly issued SSO session is created **under the same database transaction as an issuer/subject row lock**. Concurrent operator/self-unlink removes the mapping and revokes all SSO browser tokens together. A failed revocation rolls back the mapping deletion; local password and API/integration credentials remain intact.
- Self-unlink on an active SSO session clears the cookie and sends the user to the login page. Existing local sessions may continue; OIDC-only provisioned accounts cannot unlink unless a verified local-login path is provided separately.
- Keycloak back-channel logout verifies an IdP-signed logout token, applies `sid` / `sub` matching and replay resistance, then revokes matching local SSO browser sessions. RP-initiated logout is initiated by browser POST and may prompt for Keycloak confirmation. It does not remotely revoke unrelated Keycloak clients via CajeerEngine administrator APIs.
- Permissions remain CajeerEngine RBAC permissions. Local two-factor accounts additionally require verified upstream `amr` MFA evidence on OIDC login. For sensitive admin actions, the existing short-lived password/TOTP or OIDC MFA step-up remains mandatory.

## Keycloak setup and deployment

1. Take a verified DB and secret backup. Deploy the changed-files ZIP on **a complete 1.5.9 installation**; do not apply it to a 1.5.8 tree. No new SQL migration is introduced, but all migrations through `0030_authentication_assurance.sql` are required.
2. Configure a **confidential** client in Keycloak realm `skif4er` at `https://id.skif4er.ru/realms/skif4er`: Standard flow enabled, Implicit and Direct Access Grants disabled, PKCE S256, exact `https://<cms-host>/auth/oidc/callback` and registered post-logout return `https://<cms-host>/auth/oidc/logout/complete`. Disable wildcard redirect URI and web origins. Register `https://<cms-host>/auth/oidc/backchannel-logout` as a **Back-Channel Logout URL** with session-required enabled. Configure correct scopes, audience mapper (if the separate API access-token integration is enabled), and Keycloak MFA policies where required.
3. Set `APP_URL`, `SKIF4ER_ID_ENABLED=true`, a unique `SKIF4ER_ID_CLIENT_ID`, secret via a protected secrets store, and `SKIF4ER_ID_TRANSACTION_KEY`/previous keyring as documented in `.env.example`. Production uses HTTPS through a correctly configured trusted reverse proxy. Keep `SKIF4ER_ID_AUTO_PROVISION=false` unless new viewer accounts are intended.
4. Before strict session cutover: `php bin/cajeer identity:sessions:status`; `php bin/cajeer identity:sessions:migrate --yes --batch-size=100 --max-batches=10` until complete; verify on every node; set `IDENTITY_SESSION_STRICT=true` and reload **all** PHP workers only after acceptance. If already migrated in 1.5.9, do not reset the registry.
5. Run `php bin/cajeer identity:acceptance --require-oidc --probe-idp --probe-site`. This verifies config, DB state, IdP discovery and served HTTPS version, **not** a real user sign-in. Verify real browser SSO/local logins, existing-user link, self/admin unlink, TOTP/MFA, back-channel logout, concurrent logout and API token survival in staging with disposable test accounts before production traffic.
6. Enable audit retention, rate limits and ongoing Keycloak + HTTP/SQL monitoring. Ensure Keycloak signing key changes are reflected through its JWKS endpoint and PHP workers can reach the issuer securely; fail closed on failed signature or upstream verification.

## Test commands

```bash
php tools/oidc-regression.php
php tools/oidc-api-regression.php
php tools/identity-ga-integration.php --require-db
php tools/identity-center-integration.php --require-db
php tools/identity-session-acceptance-integration.php --require-db
node admin/scripts/publish-admin-assets.mjs --check
php tools/build-release.php --dry-run
```

`--require-db` requires Composer dependencies, PHP 8.4, sodium and PDO SQLite. Release CI runs the application-level test; do not interpret a local dependency skip as a passed integration test.

## Rollback

If browser login fails, keep a local break-glass administrator; disable SkiF4er ID via configuration and restore 1.5.9 code if necessary, reloading all workers. Opaque sessions revoked by unlink/logout **must not** be restored silently. Rolling back the code does not reverse OIDC link changes or restore revoked tokens. Do not disable HTTPS, CSRF, token validation or step-up to work around authentication failures. A verified database backup is necessary for disaster recovery, but restoring it can reactivate previously revoked tokens and must be treated as a separate security decision.
