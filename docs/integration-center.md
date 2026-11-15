# Integration Center — 1.3.1

## Setup and upgrade

Copy the changed files over 1.3.0, retaining `.env`, `storage/`, installed extensions and databases. No SQL migration is required: the center uses a durable file registry in `storage/app/integrations/registry.json`, shared by PHP workers on the same filesystem. Use one writable runtime volume for all workers; independent multi-host volumes are not supported. The registry is protected by flock, atomic replacement and 0600 file permissions. Backups include its encrypted contents; the transient lock is excluded.

Add these optional environment settings to the existing `.env` (do not overwrite your existing `.env`):

```dotenv
INTEGRATIONS_ENABLED=true
INTEGRATIONS_KEY=
INTEGRATIONS_ALLOWED_HOSTS=api.example.org,hooks.example.org
```

- `INTEGRATIONS_ENABLED=false` stops all send and test operations without deleting settings. Restart long-running workers after changing environment settings.
- `INTEGRATIONS_KEY` is optional; the existing `APP_KEY` is used if empty. A key must be `base64:` followed by 32 random bytes encoded in Base64. For a new independent key, generate it once with `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`. Store it only in the protected environment/secret manager, not in Git or URLs. Keep the same key across deployment and restore. Do not change a previously used master key without replacing/re-encrypting all stored credentials: this version rotates individual credentials, not the vault master key.
- `INTEGRATIONS_ALLOWED_HOSTS` is an exact, case-insensitive hostname allowlist; empty allows public HTTPS hosts. Set it to the hosts required by your installation. Provider execution uses the existing DNS-pinned, public-only outbound HTTP client (`ext-curl` required), with TLS verification, no redirects/proxy, standard port 443, and response-size and timeout limits. URLs may not include user info, query or fragment; do not place credentials in the URL path or name.
- Defaults: at most 200 connections and 64 KiB JSON payload per operation, configured in `config/integrations.php`. Response bodies are discarded (bounded to 64 KiB), not returned or logged. A failed send is not automatically retried. An in-flight operation may complete after a concurrent disable/delete; disable the integration before rotating or deleting if required operationally.

## Use in Admin

Sign in at `/admin/`, open **Интеграции**, select HTTP JSON or Signed webhook, enter the destination, credential, name, and enable if ready. Test sends a real POST with event `integration.test`, even if the connection is disabled; the global switch still blocks it. Manual send requires an enabled connection. HTTP status, success, duration and last run are shown; no remote response contents or plaintext secrets are returned. Update the credential using the separate form. Clear a bearer token with an empty value; signed webhooks require at least 16 characters. To change a credential-bound destination, create a new connection (prevents sending old credentials to a new endpoint). A connection without credentials may change URL. The existing legacy webhook API and its event outbox remain unchanged; connections in this center are explicitly executed, not automatically subscribed to CMS events in 1.3.1.

### Permissions

| Scope | Operation |
|---|---|
| `integrations:read` | List providers and connection metadata; open Admin section |
| `integrations:write` | Update name, enabled state and uncredentialed config; delete |
| `integrations:write` + `integrations:secrets` | Create a connection or replace/remove its credential |
| `integrations:run` | Test and send (real outbound traffic) |

Administrators with `*` already have these permissions. Existing non-admin roles are not silently granted new rights. Add the desired scopes to a role and issue an API token whose scopes intersect those permissions. Session requests require the existing CSRF header for writes. Audit events in `storage/logs/audit.jsonl` record operation, actor, integration ID and send status, without credentials or payloads. Do not put secrets in event names or ordinary public configuration.

## API examples

Use the existing Authorization Bearer API token or a browser session with CSRF. All responses are JSON except deletion (204). `revision` is returned by create/list/update and required for every mutation or run; stale requests return 409. Send/test return 200 with `data.ok=false` on remote or transport failure; check `ok`, not only the API status.

```http
POST /api/v1/integrations
Content-Type: application/json
Authorization: Bearer <api-token>

{"provider":"http-json","name":"Receiver","config":{"url":"https://api.example.org/events"},"secret":"<receiver-token>","enabled":true}
```

```http
POST /api/v1/integrations/<id>/send
Content-Type: application/json
Authorization: Bearer <api-token>

{"revision":1,"event":"content.saved","payload":{"id":"demo"}}
```

Other operations: `GET /api/v1/integrations`, `PATCH /api/v1/integrations/{id}`, `PUT /api/v1/integrations/{id}/secret` (revision and secret), `POST /api/v1/integrations/{id}/test` (revision), `DELETE /api/v1/integrations/{id}` (revision JSON body). HTTP JSON sends `{event_id,event,payload}` and `Idempotency-Key`; Signed webhook uses the existing WebhookDispatcher envelope and `X-CajeerEngine-Signature` (HMAC-SHA256 of the exact request body). Receivers can deduplicate by `event_id`. Each manual run creates a fresh event ID; if a timeout occurs, verify the receiver before repeating to avoid duplicate effects.

## Extending providers

Implement `CajeerEngine\Integration\Provider\ProviderInterface` with stable ID, public descriptor, configuration/credential validation, and an actual execute method returning an HTTP-style status. Register in an extension PHP provider using the kernel container's `ProviderRegistry` service before execution. Built-in providers use URL-based public configuration; custom providers must follow the same HTTPS/host policy, keep credentials exclusively in the credential argument, and never persist them or raw remote responses. The built-in admin form/API schema supports the two shipped URL-based providers; richer custom configuration UIs require an additive schema/UI extension.

Run `php tools/integration-regression.php`, `php tools/generate-api-sdk.php --check`, and `node admin/scripts/publish-admin-assets.mjs --check`. The full release CI, including Composer, real database/HTTP smoke and artifact-bound certification, remains the deployment admission gate. A local source ZIP is not a signed or certified distribution.
