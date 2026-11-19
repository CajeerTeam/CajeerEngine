# Security Hardening 1.8.2 — operator notes

This release changes live authorization, credential handling, outbound webhook delivery and failure disclosure. These are runtime fixes, not merely endpoint definitions.

## Installed security controls

1. **API tokens:** `DELETE /api/v1/api-tokens/{id}` can revoke only an active, non-session API token owned by the authenticated user. Ownership and state are checked in one SQL `UPDATE`; a guessed UUID or stale UI state cannot revoke another user's token. Browser sessions must be revoked through the session-management API. API token creation rejects malformed names, non-list scope values, invalid scope strings and expired dates. Token-management responses are `no-store`.
2. **Session registry:** browser sessions cannot be authenticated, even via a bearer header, when `UserSessionRepository` is missing or cannot validate their idle timeout/revocation record. New local/OIDC sessions fail closed if the registry is unavailable. New sessions are capped at 30 days even if a larger TTL is configured.
3. **Webhook destinations:** new and edited webhooks accept only HTTPS destinations with standard port, no URL credentials or fragments, and a public hostname/IP. DNS is checked on each actual delivery by `PublicHttpClient` and its selected public IP is pinned. Legacy HTTP/private destinations are rejected at the point of delivery too, not merely at registration.
4. **Webhook secrets:** new secrets are disclosed once on creation; list/update never return plaintext signing secrets. Updates can rotate the secret by providing a new secret of 16–256 non-control characters. Changing the destination requires providing a replacement signing secret in the same update, so previous receivers' credentials cannot be reused. The registry persists updates under the existing exclusive file lock.
5. **Delivery logs:** remote response bodies and transport exception messages are never saved; logged destination URLs contain only the origin (no query or path). The webhook list/update API hides query parameters in displayed URLs and identifies that masking with `url_has_query`.
6. **Errors:** migration diagnostics never expose underlying exception messages/classes. Production-like API runtime exceptions are returned with generic client-facing messages, while `local`, `dev` and `testing` preserve the previous error detail for debugging.

## Compatibility and deployment

- Deploy changed files from the 1.8.0/1.8.1 line as usual; **no new database migration** is needed for 1.8.2.
- **Check pre-existing webhook destinations** before deployment. Migrate HTTP URLs to HTTPS. When the destination changes, supply a new signing secret (16–256 characters). Previously configured cleartext/loopback delivery will now fail closed. Operators can use the webhook test endpoint and view the delivery status code, but not the remote server response body.
- Admin-session TTL values over 30 days now result in a maximum 30-day token; already issued tokens are unaffected unless revoked via the session registry.
- Ensure all HTTP application instances use the same backing session registry and that worker/webhook processes have access only to authorized storage and outbound networks.

## Reproducible acceptance

```sh
php tools/security-hardening-regression.php
php tools/security-regression.php
vendor/bin/phpunit --filter SecurityHardeningAcceptanceTest
php tools/ci-check.php --strict
php tools/generate-api-sdk.php --check
node admin/scripts/publish-admin-assets.mjs --check
```

The full release CI additionally performs the complete PHPUnit suite, static analysis, dependency audit and service integration tests. These gates must pass on the target deployment before describing the release as fully production-accepted. This release is **not** a penetration-test certification of every optional plugin or external integration.
