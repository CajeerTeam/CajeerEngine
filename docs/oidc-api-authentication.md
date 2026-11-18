# SkiF4er ID OIDC API Authentication — CajeerEngine 1.5.5

## Real behavior

CajeerEngine 1.5.5 can act as an OAuth 2.0 **resource server** for externally issued SkiF4er ID **access tokens**. It does not issue Keycloak tokens and does not exchange ID tokens for API authentication. The normal `Authorization: Bearer ce_...` API tokens, `X-API-Token` header and `ce_session` HttpOnly browser cookie remain unchanged. External access is **disabled by default**.

The API verifies, in order:

1. `Authorization: Bearer <JWT>` syntax (not `X-API-Token` or cookies), signed RS256 using the pinned SkiF4er ID `jwks_uri` discovered from `https://id.skif4er.ru/realms/skif4er/.well-known/openid-configuration`. Redirects and arbitrary external JWKS hosts are prohibited.
2. JWT access-token type. Keycloak's `typ: JWT` header is accepted **only** when the signed payload explicitly includes `typ: Bearer`; RFC 9068 `at+jwt` / `application/at+jwt` headers are also accepted. ID tokens, logout tokens and unsigned/incorrectly signed tokens are rejected.
3. Exact issuer, configured API audience (`aud`), permitted issuing OAuth client (`azp` / `client_id`), `sub`, expiry, issue time, not-before and the exact required OAuth scope (`cajeer.api` by default). The web client ID alone is **not** the API audience. Access token lifetime is capped at 24 hours.
4. By default, synchronous **Keycloak introspection** using the confidential client's credentials. `active` must be boolean `true`; subject and any returned audience/client/scope/expiration must not contradict the signed JWT. Invalid, revoked, offline, unavailable, or unexpected introspection results fail closed. This makes revocation effective on the next request at the cost of contacting the IdP per API request.
5. A previously established `ce_oidc_identities` mapping by `(issuer, sub)` and an active local user. **Email matches never authorize an API caller** and no users are provisioned by API token authentication.
6. The intersection between the user's **current local RBAC permissions** and `SKIF4ER_ID_API_MAX_PERMISSIONS`. External token `scope` is a gate for this resource; it never grants a CajeerEngine RBAC permission itself. No `*` permission ceiling is allowed.

An external JWT authenticates to the existing protected `/api/v1/*` routes, but cannot be used as a browser session. The following are explicitly disallowed for external JWTs: `/api/v1/auth/*` except `GET /api/v1/auth/me`, `/api/v1/identity/*`, and `/api/v1/api-tokens*`. Existing route-based authorization still applies to all remaining endpoints. No OIDC access/refresh token is stored in the database, cookie, SDK or audit logs.

## Configuration

Register a **dedicated resource audience** in Keycloak, for example `cajeerengine-api`, using an Audience protocol mapper. Assign a client scope for `cajeer.api` to **approved requesting OAuth clients** and ensure the access token contains both values. Authorize only known callers in `SKIF4ER_ID_API_ALLOWED_CLIENTS`. The CajeerEngine confidential OIDC client must be able to introspect tokens. Use an HTTPS deployment with a valid client secret.

```dotenv
SKIF4ER_ID_ENABLED=true
SKIF4ER_ID_CLIENT_ID=cajeerengine-web
SKIF4ER_ID_CLIENT_SECRET=YOUR_SERVER_ONLY_SECRET
SKIF4ER_ID_API_ENABLED=true
SKIF4ER_ID_API_AUDIENCE=cajeerengine-api
SKIF4ER_ID_API_ALLOWED_CLIENTS=partner-frontend,cajeerengine-web
SKIF4ER_ID_API_REQUIRED_SCOPE=cajeer.api
SKIF4ER_ID_API_MAX_PERMISSIONS=content:read,media:read,system:read
SKIF4ER_ID_API_INTROSPECTION=true
```

These settings are read when the application initializes. The default permission ceiling is explicitly configured **read-only**; add specific write permissions only after auditing the client and application routes. A missing/invalid audience, allowed client list, permission ceiling, or confidential client configuration disables external JWT authentication but leaves local login available.

Example request:

```http
GET /api/v1/auth/me HTTP/1.1
Host: cms.example.com
Authorization: Bearer <KEYCLOAK_ACCESS_TOKEN_WITH_CAJEER_API_AUDIENCE>
```

The response includes the local CajeerEngine user (not the external provider's credentials), effective scopes, and `auth_source: oidc_access`; it has `cookie: false` and `session: false`.

If immediate IdP revocation checking is not required, `SKIF4ER_ID_API_INTROSPECTION=false` accepts locally verified, unexpired JWTs only. Revoked tokens may then remain accepted until expiry. **Do not disable introspection for sensitive APIs** without a documented incident-response policy. JWKS and discovery are retrieved under the existing TLS, URL pinning and response-size limits; a Keycloak outage fails external auth closed. For high QPS traffic, profile Keycloak capacity before enabling this mode.

## Testing and upgrade

No SQL migration is needed; existing `ce_oidc_identities` and users/roles are reused. The existing OIDC callback, SSO logout and local session paths are unchanged. Upgrade the code, set the env vars explicitly, restart PHP workers, and test with a real access token issued for the API—not a browser ID token.

- `php tools/oidc-api-regression.php`: 27 signed JWT / introspection protocol scenarios with controlled transport (runs without Composer or PDO).
- `php tools/oidc-api-integration.php --require-db`: real PHP PDO SQLite, AuthService, local users/roles/tokens and HTTP middleware checks, required by release CI (needs Composer and `pdo_sqlite`).
- Existing `php tools/oidc-regression.php` and `php tools/oidc-global-logout-regression.php`: ensure ID token login and SSO logout remain compatible.

**Production acceptance:** verify valid/revoked tokens, JWT audience mapping, allowed client restrictions and roles with the registered SkiF4er ID realm. This integration requires live Keycloak credentials for final E2E and cannot be certified solely using deterministic local test keys.
