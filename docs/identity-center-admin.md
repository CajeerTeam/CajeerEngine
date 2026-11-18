# CajeerEngine 1.5.7 — Identity Center Admin UX

1.5.7 implements real local identity administration in `/admin/#/identity` (shipped static SPA) and `/admin/identity` (Nuxt source). It uses the existing CajeerEngine SQL database, browser sessions, roles, OIDC mappings and audit log. It **does not** call the Keycloak Admin REST API: the published [SkiF4er ID SDK](https://id.skif4er.ru/sdk/) documents OIDC Authorization Code + PKCE S256, bearer verification and licensing, but not administrative identity CRUD.

## Operations

- Search local users by email, name or UUID (escaped, case-insensitive), filter linked/unlinked, and paginate 30 records at a time. Inspect active/inactive status, local TOTP flag, verified binding `sub`, provisioning flag and binding date.
- Select a local account and view its **active SkiF4er ID browser sessions**, with user agent, masked network and last activity. The UI never receives access/refresh/ID tokens or Keycloak client credentials.
- **Prelink** an existing active CajeerEngine user to an externally verified, exact Keycloak subject (not an email). Operators must independently validate ownership of the Keycloak account and enter the same `sub` twice. The server enforces one subject per user and rejects a subject already mapped to a different user.
- **Unlink** an existing, non-provisioned external identity and atomically revoke that local user's OIDC browser sessions. A user provisioned solely through OIDC **cannot be unlinked** until a verified local login method is available (current 1.5.6 protection). This action does not delete Keycloak accounts, clear IdP SSO cookies, revoke local password sessions or invalidate unrelated API tokens.
- Revoke one or all local SkiF4er ID browser sessions without modifying the account binding. The back-channel logout handler remains available for provider-driven global SSO revocation.
- Operators cannot use privileged prelink, unlink or session controls on **their own** account. Use the existing self-service flow above the admin table for personal identity management.

## Access control

The API uses existing `ce_session` HttpOnly cookies and requires HTTPS for every Identity Center operation. `GET` operations need local `users:read`. Mutations require the existing local administrator `*` scope and **fresh** session-bound assurance from v1.5.6 (password and TOTP when enabled, or a signed OIDC `auth_time` with MFA evidence). Mutations additionally require CSRF; unlink and bulk revocation require a matching local UUID confirmation. A personal API token or external JWT cannot impersonate an administrative browser session. No Keycloak realm role increases local CajeerEngine permissions.

Successful changes are audited as `identity.admin_link`, `identity.admin_unlink`, `identity.admin_session_revoke` or `identity.admin_sessions_revoke_all`. Audit entries include actor ID, target local user UUID, and for link changes **SHA-256 digest of the external subject**, never secrets. Persistent link modifications use SQL transactions; unlink and session revocation are committed together.

## Deployment

1. Back up the database, `.env`, `public/admin/` and `storage/` before upgrading.
2. Extract the patch files over the previous **1.5.6** tree, preserving paths; restart PHP workers and invalidate cached admin assets.
3. No new migration or Keycloak service key is required. Existing `0026`, `0027`, `0028`, `0029`, `0030` migrations and local session tables must already be applied; run `php bin/cajeer migrate` if upgrading from older revisions.
4. Open `/admin/#/identity` as an admin with `*` permission. Confirm a local password/TOTP or OIDC MFA step-up before mutating. Perform prelink only when you have independently verified the subject. To manage Keycloak users or configure MFA flows, use its separate Admin Console with its normal access controls.

## API

| Method | Path | Permission | Description |
| --- | --- | --- | --- |
| GET | `/api/v1/identity/admin/users?q=&linked=all&limit=30&offset=0` | `users:read` | User/mapping list and total |
| GET | `/api/v1/identity/admin/users/{id}/sessions` | `users:read` | Active OIDC browser sessions |
| POST | `/api/v1/identity/admin/users/{id}/link` | `*` + step-up | `{ "subject": "...", "confirm_subject": "..." }` |
| DELETE | `/api/v1/identity/admin/users/{id}/link` | `*` + step-up | `{ "confirm_user_id": "..." }` |
| DELETE | `/api/v1/identity/admin/users/{id}/sessions/{session}` | `*` + step-up | Revoke one |
| POST | `/api/v1/identity/admin/users/{id}/sessions/revoke-all` | `*` + step-up | `{ "confirm_user_id": "..." }` |

The `subject` is the OIDC `sub` claim and **must be independently verified** by an operator (do not derive from email or `preferred_username`). The issuer is pinned to `https://id.skif4er.ru/realms/skif4er`, consistent with the SDK documentation. API errors are structured JSON; `403 step_up_required` requires reauthentication.

## Checks

```sh
php -l core/Identity/IdentityCenter.php
php -l core/Api/Controller/IdentityAdminController.php
node --check public/admin/assets/admin-app.js
node admin/scripts/publish-admin-assets.mjs --check
php tools/generate-api-sdk.php --check
php tools/identity-center-integration.php --require-db
python3 tools/identity-center-browser-regression.py
php bin/cajeer api:lint --strict --json
```

CI runs the PHP integration tests with a real SQLite PDO driver and Composer/Symfony dependencies. Local browser tests use real Chromium interactions with a mocked API to verify the shipped interface and transport. For final staging acceptance, test both local TOTP and Keycloak MFA against a running IdP; **no live Keycloak tenant or production database is accessible from the provided archive alone**.
