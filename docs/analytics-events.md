# Analytics Events — CajeerEngine 1.3.4

## Deployment

Upgrade from 1.3.3 by replacing release files and preserving `.env`, database and `storage/`. No SQL migration is required. Counter settings remain unchanged. The consent policy hash now includes event rules; visitors must choose their consent again. Open `/admin/#/analytics-events` to configure event names, per-provider mappings, automatic events and optional diagnostics. On static sites rebuild/export after configuration changes. No analytics script loads while consent or tracking is disabled.

## Browser event API

```js
// Only call after the application has confirmed success, not on button click.
CajeerAnalytics.emit('generate_lead', {value: 10, currency: 'USD'});
CajeerAnalytics.emit('form_submit', {}, {id: '0123456789abcdef0123456789abcdef'});
CajeerAnalytics.pageView('/articles/news');
CajeerAnalytics.getJournal();
CajeerAnalytics.getStatus();
```

`CajeerTracking.event()` and `pageView()` remain available. Event names must be lowercase ASCII, 1–40 characters; reserved Google/internal prefixes and commerce events (`purchase`, `refund`, `view_item`, `add_to_cart`, `begin_checkout`) are rejected in the browser. The optional `id` is 32 lowercase hexadecimal characters; reuse it for a logical event within the document. The runtime deduplicates up to 512 IDs per document and limits processing to 120 events/minute. The SDK owns its vendor-side initialization queue; `queued` means a command has been passed to that queue, `submitted` means a loaded library received a command, `failed` means a detected local error. None confirms receipt or processing by the vendor. No browser command is replayed after revocation. GA4 receives `event_id`; GTM receives `ce_event_id`. Metrika uses `reachGoal` with configured goal name. Global cross-session deduplication is not guaranteed by the browser SDK or the vendors.

Automatic events: search results (only numeric result count), download-link clicks (only recognized file extension), external-link clicks (no destination), scroll thresholds 25/50/75/90. Use `data-ce-analytics-ignore` on an anchor or ancestor to exclude it. Downloads represent a click, not successful completion. Form submissions, signups, logins and leads are explicit application success hooks, not inferred from arbitrary form submissions. The engine has no native commerce order lifecycle; browser purchase events are rejected. Default GA4 automatic-event mappings use `site_search`, `site_file_download`, `site_outbound_click`, `site_scroll` to avoid collision with Enhanced Measurement. Disable overlapping Enhanced Measurement tags and theme/plugin tracking; remote GTM container configuration must implement corresponding triggers and consent requirements. Direct GA4/Metrika commands and GTM are still mutually exclusive per 1.3.3. The runtime does not forward query strings or search terms explicitly; vendor SDKs can inspect the page, so avoid sensitive data in URLs and review provider settings.

## Configuration and optional diagnostics

`GET/PUT /api/v1/tracking/events` (`tracking:read`/`tracking:write`) stores revision-controlled rules under `storage/app/privacy/events.json`. Blank provider mapping disables that route. `page_view` mapping is fixed. `allow_custom` permits unmapped manual names. Disable global event processing to stop managed counters. Admin routing preview evaluates saved configuration and does not send synthetic traffic.

Optional `collect_diagnostics` (off by default) uses `/api/v1/public/analytics/events` on dynamic pages after consent. Same-origin JSON only, 2 KiB body, permitted host, current policy version, configured event names, strict outcome fields; independent `RATE_LIMIT_ANALYTICS=60` per IP/window. Browser requests omit credentials. Journal records only event ID, configured name, timestamp and outcomes; no parameters, URLs, user IDs, IPs or consent version are stored in the journal. Server cannot prove a browser's consent or actual vendor delivery from this untrusted diagnostic request, so it is not a source of billing or conversion truth. Diagnostics are best effort, bounded to 4 concurrent sends and a single transport retry. Local journal holds 100 records and clears on revocation; server journal retains up to 2000 records, lazily purged after 7 days, read/clear through protected admin routes. Server logs/rate limiter may retain request metadata under their own policies. Static exports do not use the collector.

## Trusted server delivery

Server API is disabled unless `ANALYTICS_SERVER_TARGETS` contains explicit comma-separated UUIDs of enabled Integration Center connections. Configure a protected HTTPS HTTP JSON or Signed Webhook receiver, then set the allowlist on both web and worker processes. Credentials remain in the existing encrypted vault. A dedicated non-session API token must have **both** `tracking:write` and `integrations:run`. Only a trusted application backend can issue consent leases and submit confirmed business events. This is not a direct GA4 Measurement Protocol API; the configured receiver must translate to a vendor if required. Do not expose the server token to browser code.

1. Retrieve the current policy version from `/api/v1/public/consent`. The trusted application must verify that the visitor has valid analytics consent for that exact version and store its own evidence; a lease is not proof of consent by itself.
2. `POST /api/v1/tracking/events/server/grants` with `{"version":"<64 hex policy version>","ttl_seconds":300}`. Store returned `grant_id` alongside the application's consent state; max TTL 900 seconds. On withdrawal, the application must immediately `DELETE /api/v1/tracking/events/server/grants/{id}`. The browser's local consent is not automatically synchronized to these backend leases. If unable to verify/synchronize consent, do not use server delivery.
3. After confirming the actual order/lead/etc. on the backend, `POST /api/v1/tracking/events/server` with:

```json
{
  "grant_id": "0123456789abcdef0123456789abcdef",
  "id": "fedcba9876543210fedcba9876543210",
  "name": "purchase",
  "integration_id": "12345678-1234-1234-1234-123456789abc",
  "revision": 1,
  "value": 199.99,
  "currency": "USD"
}
```

Use an opaque stable ID for a logical business event; never embed raw customer/order identifiers. Allowed server event names: purchase, refund, generate_lead, form_submit, sign_up, login. Only value and currency travel in the signed or authenticated integration envelope. `id`, grant and target revision are bound to queue payload; retry unacknowledged requests with the same input. Each lease allows 30 events. The queue runs `analytics.deliver` through the existing default worker: `php bin/cajeer queue:work --limit=10`. Before each send it checks the lease, policy version, target allowlist and connection revision. Revocation serializes with sending, so an in-flight HTTP request cannot be recalled; later attempts cancel. Failures enter existing queue backoff/retry, and expired/revoked jobs are consumed without sending. `GET /api/v1/tracking/events/server/grants/{id}` returns delivery states, visible for one hour after lease expiry. HTTP 2xx confirms receiver acceptance, not subsequent delivery to a vendor. Receiver must deduplicate `Idempotency-Key` across crashes/retries; exactly-once remote delivery is impossible without receiver cooperation. Queue records follow existing retention settings. No visitor/customer identifiers or API secrets are sent in payloads.

## Operations and checks

File-based settings/diagnostics/lease store require a common local/shared filesystem with reliable locks and atomic writes for multiple processes. Server event throughput is intentionally bounded; lease lock serializes sends/revokes. Backups include settings but omit diagnostics and transient consent leases; restored queued events without a lease fail closed. Use HTTPS and correct trusted reverse-proxy configuration for origin verification. Tests: `php tools/analytics-events-regression.php`, `python tools/analytics-events-browser-regression.py` (Playwright/Chromium), `php tools/tracking-http-regression.php` (Composer + PDO SQLite). External analytics accounts and a real HTTPS receiver are required to verify live ingestion.
