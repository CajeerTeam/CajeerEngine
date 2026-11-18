# CajeerEngine 1.5.6 — RBAC & Authentication Assurance

This release provides **working** short-lived reauthentication for sensitive CMS changes. It does **not** copy Keycloak realm roles into local CMS grants.

## Installation

1. Back up your database, `.env` and `storage/` before upgrading 1.5.5.
2. Apply all changed files, then run `php bin/cajeer migrate` to apply `0030_authentication_assurance.sql` on PostgreSQL, MySQL or SQLite.
3. Restart PHP workers; rebuild/distribute Nuxt if used. The production static SPA and its manifest are included in the patch.
4. For external step-up, keep a registered SkiF4er ID confidential client, exact HTTPS redirect `https://YOUR_CMS/auth/oidc/callback`, PKCE S256 and **MFA configured on Keycloak**. The normal SSO login is unchanged.

## Security model

Local `ce_users` / `ce_user_roles` and permission intersections remain authoritative. A verified external realm role **never** creates a CajeerEngine role. Every request recomputes the user's local permissions. An administrator may optionally configure `SKIF4ER_ID_SESSION_MAX_PERMISSIONS=content:read,media:read,system:read` to limit OIDC **browser** sessions further. It is a ceiling, never a grant, and is evaluated on every request. The optional ceiling does not affect local sessions or the separate 1.5.5 external API resource-server permissions.

A 1.5.6 migration adds `assured_at`, `assurance_method`, and `assurance_mfa` to `ce_user_sessions`. Assurance is bound to one existing browser session and disappears on its expiry, revocation or rotation. No password, TOTP, Keycloak ID/access/refresh token, or raw `sid` is saved in these fields. By default assurance expires in **300 seconds**, configurable via `AUTH_ASSURANCE_TTL_SECONDS` (clamped to 60–900 seconds).

After RBAC and CSRF checks, the middleware requires fresh assurance for **mutations** of users, roles, API tokens, and credential/configuration changes to Integration Center. Read operations and integrations' authenticated delivery/test operations remain supported. Even a personal API Bearer token with `*` cannot bypass these privileged browser-only mutations. The middleware uses the **matched Symfony route name**, avoiding path encoding bypasses.

### Local account confirmation

`POST /api/v1/auth/assurance/confirm` accepts the current **local password** and `two_factor_code` where local TOTP is enabled. It must have a valid HttpOnly `ce_session` cookie and CSRF header. TOTP must be live; recovery codes are not silently used for privileged step-up. Incorrect credentials never stamp assurance. `GET /api/v1/auth/assurance` shows the state of the current session (no secrets). Local accounts can perform the same confirmation even when their current session originated from OIDC, provided they know a valid local password.

### SkiF4er ID confirmation

The browser sends a CSRF-protected `POST /api/v1/identity/step-up/start`. Only an active **OIDC browser session** can initiate it. The server creates a fresh one-time transaction, pins it to the current user and session UUID, and sends the user through **Authorization Code + PKCE S256** to SkiF4er ID with `max_age=0&prompt=login`. The normal callback consumes the state and checks the ID token through the existing RS256/JWKS verification. It additionally checks the same verified `(issuer, sub)` identity, an integer `auth_time` no more than three minutes old (30 seconds clock allowance), and an `amr` containing verified `mfa`, `otp`, `hwk` or `swk`. A mere successful SSO cookie, untrusted client-side `acr` value or `amr: ["pwd"]` is **insufficient**. A failure leaves all previous sessions and grants unchanged. The callback redirects back to the Identity page **without creating a new session**.

This release specifically requires the IdP to emit `auth_time` and MFA evidence for privileged OIDC operations. Configure a Keycloak browser flow/mapper that emits valid claims. If the provider cannot supply those claims, the operation fails closed; use a separately verified local password/TOTP if available.

### Using the UI

The production `/admin/#/identity` page includes local confirmation and a SkiF4er ID MFA button. When a privileged action returns `403 step_up_required`, the production SPA offers immediate local reauthentication or starts IdP reauthentication; it retries a local operation only once after success. Because an OIDC step-up navigates to Keycloak, repeat the original operation after returning to Identity. The Nuxt Identity page includes equivalent explicit actions; Nuxt API errors direct the user there.

### APIs

```
GET  /api/v1/auth/assurance
POST /api/v1/auth/assurance/confirm
POST /api/v1/identity/step-up/start
```

Each operation is represented in the OpenAPI 3.1 contract and generated PHP/TypeScript SDK. Authentication failures use 401/403, malformed inputs 422, unavailable external transactions 409/503. `403 step_up_required` means authenticate again; it never means a grant can be bypassed.

## Acceptance tests

```bash
php tools/assurance-oidc-regression.php
python3 tools/assurance-sqlite-regression.py
php tools/assurance-integration.php --require-db
python3 tools/assurance-browser-regression.py
php bin/cajeer api:lint --strict
php tools/ci-check.php
```

The PHP integration test requires Composer Symfony libraries, PDO SQLite and `mbstring`, and is a **mandatory** release CI step. The deterministic signed-key OIDC protocol test and SQLite migration test also run outside that environment. Test with real SkiF4er ID users and configured MFA in staging before turning on production privileged flows. There is no automatic privilege mapping or secret migration.
