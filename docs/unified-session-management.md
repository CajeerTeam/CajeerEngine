# Unified Session Management — CajeerEngine 1.5.3

## What works

CajeerEngine continues using opaque, SHA-256-hashed `ce_...` credentials in `ce_api_tokens` and the `ce_session` `HttpOnly`, `Secure` (HTTPS) cookie. This release adds a SQL session row for each successful local or SkiF4er ID login. The row records ownership, provider (`local`, `skif4er`, or `legacy`), sanitized browser user agent, masked network prefix, creation and last activity, expiration and revocation. For an OIDC session, only a SHA-256 digest of the provider session identifier is stored; ID/access/refresh tokens and the original OIDC subject are never exposed in the session list.

Sessions created before 1.5.3 are registered as `legacy` on their first **valid** authenticated request. Their original token creation time, last activity (or creation time if never used) and expiry are preserved. The idle limit is applied even to these older sessions. Expired or revoked tokens cannot be imported. API tokens with other names are never registered or revoked by this subsystem.

## HTTP endpoints

All endpoints require an active **browser cookie** session (not only `Authorization: Bearer`) and HTTPS in production. Mutations also require the existing `X-CSRF-Token` header, obtainable through `/api/v1/auth/me`.

| Method | Path | Behavior |
| --- | --- | --- |
| GET | `/api/v1/auth/sessions` | List up to 100 active sessions owned by the current user; mark the current session |
| DELETE | `/api/v1/auth/sessions/{id}` | Revoke one owned session, clear cookie when the current one is revoked |
| POST | `/api/v1/auth/sessions/revoke-others` | Revoke all other owned sessions |
| POST | `/api/v1/auth/sessions/revoke-all` | Revoke every owned session and clear current cookie |
| POST | `/api/v1/auth/sessions/rotate` | Replace the current cookie credential and CSRF token atomically |

`id` is the opaque database UUID of a session record, **not** the credential itself. `device` is user-agent metadata, not cryptographically verified device identity. `network` is at most an IPv4 /24 or IPv6 /48 prefix; no full IP address is returned. A deleted or foreign ID results in 404 or `revoked: false`; the user can only affect their own browser sessions. Normal API tokens remain independent.

The shipped production admin UI (`/admin/#/identity`) and the Nuxt admin page (`/identity`) expose these actions. After rotating, replace the in-memory CSRF token with the returned `csrf_token`; the old cookie and CSRF are immediately invalid. After revoking the current session, return to the login page.

## Database and configuration

**Run `php bin/cajeer migrate` before enabling the new code.** Migration `0028_unified_sessions.sql` creates `ce_user_sessions` in SQLite, PostgreSQL and MySQL/MariaDB. The active credential remains in `ce_api_tokens`; both the token and session row are revoked in one SQL transaction. The registry uses existing DB transactions and single-use opaque credentials—no parallel authentication system is introduced.

- `SESSION_TTL_DAYS` (existing, default `14`) is the absolute expiration of a newly issued token.
- `SESSION_IDLE_MINUTES` (new, default `720`) expires a session after inactivity, checked on every request. The minimum enforced value is 5 minutes.
- Activity timestamps are touched at most once per minute to reduce database writes. One user's revocation never affects another user's tokens.
- Existing user suspension and 2FA changes already revoke their API tokens. The session listing joins those tokens, so revoked credentials do not appear as active.

For external OIDC login the local CajeerEngine session can be revoked immediately, but this **does not** revoke the SkiF4er ID / Keycloak SSO session. RP-Initiated Logout, Back-Channel Logout and token refresh handling are tracked for the next release (1.5.4); they are not simulated in 1.5.3. No new automatic OAuth refresh token is persisted.

## Validation before production

Use `python3 tools/unified-sessions-sqlite-regression.py` for real SQLite DDL and transactional invariants. On a PHP host with `pdo_sqlite` and Composer dependencies, run `php tools/unified-sessions-regression.php --require-db` for actual AuthService and SQL lifecycle tests. After upgrading, verify local login, linked SkiF4er login, cookie and CSRF, list/revoke/rotate, and session expiration from two independent devices. Confirm that integration API tokens continue working and that rollback restores the database snapshot if required.
