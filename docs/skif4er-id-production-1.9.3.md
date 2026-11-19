# CajeerEngine 1.9.3 — SkiF4er ID Production

## Scope

This release hardens the **existing operational SkiF4er ID login and inter-service authentication**. It changes server-side OIDC validation and go-live acceptance, without creating a new identity service or requiring new database migrations.

- OIDC Authorization Code + PKCE remains confidential; authorization and ID/access/refresh tokens are never returned to browsers. The CMS keeps an independent, revocable opaque browser session.
- `OidcClient::validateIdToken` rejects OAuth bearer/access-token types even with a valid Keycloak signature, client audience and nonce. It accepts genuine Keycloak ID tokens with an absent/ID `typ` claim and a JWT ID-token header.
- Online introspection (when enabled) rejects a partial `active:true` response. The response must bind **subject, OAuth client, granted scope and unexpired lifetime** to the already signature-validated access token. Outages fail closed. Keycloak must be configured to return these fields.
- `identity:acceptance` now checks the actual installed `VERSION` against `release.json` and, with `--probe-site`, the version currently served by PHP workers. It no longer incorrectly expects 1.6.0.
- `--probe-idp` connects to **the pinned HTTPS realm** at `https://id.skif4er.ru/realms/skif4er`, retrieves its discovery document and JWKS, confirms a usable RS256 key, and checks the presence of introspection and logout endpoints. It never requests end-user tokens.

## Install and upgrade

1. Back up the current database, `.env`, identity credentials and any persistent browser-session tables. Complete ongoing upgrades and stop the worker and scheduler before replacing files.
2. Apply the **changed-only** 1.9.3 archive over 1.9.2; preserve the original file paths. No new SQL migration is included.
3. Keep the previously configured Keycloak confidential client and exact HTTPS callback path `/auth/oidc/callback`. Review the realm's **introspection**, **back-channel logout**, and **post-logout redirect** settings.
4. Restart PHP-FPM and reverse proxy processes. Confirm every node serves 1.9.3, not cached PHP opcodes from the previous version.
5. In an appropriately isolated environment, run:

   ```bash
   php tools/skif4er-id-production-regression.php
   php tools/oidc-regression.php
   php tools/oidc-api-regression.php
   php tools/oidc-global-logout-regression.php
   php bin/cajeer identity:sessions:status --json
   php bin/cajeer identity:acceptance --require-oidc --probe-idp --probe-site --json
   ```

   The final command needs `SKIF4ER_ID_ENABLED=true`, a correct confidential client, configured encryption keys, reachable Keycloak and a working HTTPS deployment. It intentionally **fails** when the IdP is inaccessible or signing keys are absent. It does not certify actual user MFA or interactive login by itself.

## Staging production acceptance (real Keycloak)

Use disposable real test identities and both a privileged and unprivileged local CMS account. Record test times, software versions, identities with values redacted and the resulting request IDs.

1. Confirm OIDC code + PKCE login creates an **opaque HTTP-only CMS session**, not a browser-visible provider access token; validate expiration, session idle timeout, rotation and remote 401/403 handling.
2. Link an existing active local account with password + configured TOTP and verify the correct fixed provider subject, then test unlink and immediate revocation of *only* that user's SSO sessions. Local and integration API tokens must remain unaffected.
3. Authenticate two CMS/API services against the same Keycloak realm. Check that each resource server enforces its own `aud`, allowed clients and required scope. Test successful and denied introspection, wrong client, revoked token, timeout and Keycloak downtime. Never disable resource-server introspection without a separately reviewed revocation policy.
4. Verify login/session invalidation after account disablement, role removal and token revocation. Ensure administrator endpoints cannot be called with an external bearer or another account's cookie.
5. Check MFA step-up for high-risk actions: a fresh Keycloak `auth_time` and verified MFA `amr` are required, and expired/replayed OIDC state is rejected.
6. Test RP-initiated logout, browser cookie removal, and actual Keycloak back-channel logout for one device, multiple devices and duplicate logout JWTs. Verify that local credentials and unrelated users stay active.
7. Restart PHP-FPM and IdP in both orders; verify audit logs contain no raw ID/access/refresh token, PKCE verifier or confidential client secret.
8. Repeat on each supported production proxy/TLS profile and database; run the full release CI, migration and restore acceptance before marking GA approved.

## Rollback and incidents

- Save verified database/credential backups and record the exact release archive digest before upgrading.
- For an IdP outage, **do not** bypass signature, issuer, audience, MFA or introspection requirements. Existing local break-glass access should be configured and secured in advance.
- If a new Keycloak claim mapping causes 401 errors, check `client_id`, `sub`, `scope`, `exp` and audience in the Keycloak introspection configuration **without logging the raw token**. Correct the IdP configuration rather than relaxing the resource-server verifier.
- Roll back using the documented signed-update recovery procedure and restore a compatible database snapshot if needed. This release has no migration to reverse.

## Limitations

The offline RSA/JWKS tests, local static audit and successful source build do **not** prove a live Keycloak login, multi-node session revocation, full PHPUnit, server database integration, production dist readiness or a signed release. Those are separate mandatory staging/CI gates.
