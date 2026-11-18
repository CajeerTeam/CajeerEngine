# SkiF4er ID SSO & Global Logout — CajeerEngine 1.5.4

## Live behavior

The existing Authorization Code + PKCE flow uses the common `skif4er` realm at `https://id.skif4er.ru/realms/skif4er`. This permits SSO with other *independently registered* clients already signed into Keycloak. No session cookie is shared between applications.

Local logout (`POST /api/v1/auth/logout`) continues to revoke only the current CajeerEngine session. For a current **SkiF4er ID** browser session, the Identity page also offers **Выйти из SkiF4er ID (SSO)**. It POSTs to `/api/v1/auth/oidc/logout` with the existing HttpOnly `ce_session` and CSRF header. The backend verifies the session's provider, fetches the pinned `end_session_endpoint`, revokes the local session and clears its cookie, then responds with the provider URL. The browser redirects to Keycloak; Keycloak terminates its SSO session (subject to Keycloak's confirmation policy), then returns to `/auth/oidc/logout/complete`. If Keycloak is down the local session is still revoked once a logout URL was issued; it is not possible to assert remote logout succeeded until Keycloak confirms it. An arbitrary API bearer token, an anonymous GET, and a local-password session cannot initiate this global logout.

## Keycloak client registration

Register a **confidential** client for CajeerEngine with standard code flow and PKCE S256. Configure:

- Valid login callback: `https://cms.example.com/auth/oidc/callback`.
- Valid post-logout redirect URI: `https://cms.example.com/auth/oidc/logout/complete` (exact, no wildcard).
- Back-channel logout URL: `https://cms.example.com/auth/oidc/backchannel-logout`.
- Enable the client's Back-Channel Logout setting. Enable session ID (`sid`) in logout tokens when available. The implementation also supports spec-compliant subject-only tokens.
- `APP_URL=https://cms.example.com`, `SKIF4ER_ID_ENABLED=true`, plus the existing client ID/secret/key settings. All external endpoints are pinned to the SkiF4er realm. HTTPS and correct trusted-proxy setup are required.

### Back-Channel Logout

Keycloak POSTs `application/x-www-form-urlencoded` with a `logout_token` field. This public endpoint accepts **only** a signed RS256 JWT verified against the pinned JWKS URI. It checks issuer, client audience/azp, `iat`, `exp`, optional `nbf`, `jti`, exactly the OpenID Back-Channel Logout event, no `nonce`, and at least `sid` or `sub`. There are limits on token size and accepted clock skew. The token itself and raw session/subject identifiers are **never** logged or stored. It records a SHA-256 digest of issuer+`jti` to prevent replay until expiration.

Matching local OIDC sessions are revoked **atomically** with the replay record in SQL. If both `sid` and `sub` are supplied, both restrict the affected sessions. Ordinary local password browser sessions and personal API tokens remain valid. Replayed events are accepted idempotently without a second revocation. A storage failure returns a retryable error, not a false acknowledgement. The API remains safe if the target session was already revoked.

Previously issued 1.5.3 OIDC sessions stored a hashed `sid` but not a subject digest. `sid` logout works for them; subject-only logout is supported for older sessions while the identity link is still present. New 1.5.4 sessions retain hashed `sid` and `sub`. Neither refresh tokens nor remote Keycloak admin revocation are implemented.

### Migration & operations

Run `php bin/cajeer migrate` **before** enabling the new code. Apply `0029_oidc_global_logout.sql` on SQLite, PostgreSQL or MySQL; this adds `ce_user_sessions.oidc_sub_hash` and `ce_oidc_logout_replay`. Preserve the database, encryption keys and existing `ce_session` cookies. The local emergency login path is unchanged. The public back-channel endpoint needs reachability from Keycloak; protect it with normal TLS/reverse-proxy rules, **do not** add interactive cookie authentication or CSRF to that route.

The sign-out action works only after an OIDC login; the default ordinary session logout deliberately remains local. Observe Keycloak's account session screen and another test application to verify global logout propagation. No DNS, other clients or Keycloak settings are modified by this release.

### Release checks

`php tools/oidc-global-logout-regression.php` verifies RS256 signatures and invalid token rejection against a generated RSA key. `python3 tools/oidc-global-logout-sqlite-regression.py` applies the migration and checks SQL constraints. `php tools/oidc-global-logout-integration.php --require-db` exercises real PDO SQLite session revocation, subject/sid targeting, idempotence and non-OIDC token isolation. The latter runs in release CI and needs Composer vendor and `pdo_sqlite`. Full E2E must also be tested against a registered SkiF4er ID client and at least two real applications; the local deterministic tests cannot certify live provider availability.
