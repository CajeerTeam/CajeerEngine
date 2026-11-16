# Infrastructure Analytics — 1.3.8

Cloudflare Edge Analytics and Cloudflare Web Analytics are separate reporting sources in **Admin → Analytics Hub** (`/admin/#/analytics-hub`). Both use the existing encrypted credential store, SQL snapshots, RBAC, queue and scheduler. No migration is required when upgrading from 1.3.7. Keep `storage/`, the database and `INTEGRATIONS_KEY` / `APP_KEY`, and restart worker/scheduler.

## Edge analytics

1. In Cloudflare, copy the Zone ID of a proxied domain. Create a scoped API token with Zone Analytics Read for that zone. Do not use the global API key. The optional Security Events dataset may require additional access; it is not needed for the basic traffic report.
2. In Analytics Hub, create `Cloudflare Edge Analytics` with the 32-hex Zone ID as Resource. Use this credential JSON (replace the placeholder):

```json
{"type":"api_token","token":"YOUR_CLOUDFLARE_API_TOKEN"}
```

3. Enable the source and synchronize. Process the queue with `php bin/cajeer queue:work --once` or the normal worker. The existing scheduler requests the last 30 completed UTC days hourly. Select a saved period in Analytics Hub or request a period of 1–30 days. Retention and allowed windows depend on the dataset and Cloudflare plan; try a shorter/recent range when the API rejects a date range.

The fixed `httpRequestsAdaptiveGroups` GraphQL queries request aggregate HTTP count, transferred response bytes and Cloudflare visits for `requestSource: eyeball`, with overview, daily, country, cache status, and edge response status breakdowns. The cache HIT share is calculated from the returned cache status breakdown (not a Cloudflare dashboard-equivalent cache efficiency metric). HTTP 4xx/5xx totals are calculated from the returned status breakdown when it is not truncated; no totals are fabricated when there are no rows or the breakdown is unavailable. The optional mean edge TTFB is in milliseconds. Optional `firewallEventsAdaptiveGroups` aggregates security events by action; these are security events, not distinct requests, and are not added to traffic counts. Sampled estimates are not multiplied by sample interval. Data quality/truncation is displayed; missing days stay missing. No user IP, URL query strings or request logs are requested.

## Cloudflare Web Analytics (RUM)

1. Add the site in Cloudflare Web Analytics. Copy its **Site Tag** (32 hex), the **Account ID** (32 hex) and create a scoped API token permitted to read account analytics / Web Analytics for that account. The reporting resource is the site tag, not necessarily the public beacon token. Check the exact granted permissions in Cloudflare's token UI.
2. Create `Cloudflare Web Analytics / RUM` in Analytics Hub. Set Resource to the Site Tag. Credential JSON:

```json
{"type":"api_token","token":"YOUR_CLOUDFLARE_API_TOKEN","account_id":"YOUR_32_HEX_ACCOUNT_ID"}
```

3. Synchronize and open the report. `rumPageloadEventsAdaptiveGroups` reports pageviews and visits (Cloudflare's referrer-based visit definition), daily trends, pages, countries and devices. `rumWebVitalsEventsAdaptiveGroups` returns P75 LCP and INP (converted from microseconds to milliseconds), and unitless CLS; negative sentinel values mean unavailable. The values are percentiles of the selected period, not averages of daily percentiles. Optional unsupported datasets are displayed as unavailable. Cloudflare may retain less history for some RUM datasets, restrict fields by plan/permissions, or not yet have received beacon data.

## Browser beacon

In **Tracking & Counters** (`/admin/#/tracking`), enter the public Web Analytics Site Token (32 hex) in the Cloudflare field, enable tracking, and select exact site hostnames. This field is public, not an API token. Configure Cloudflare for manual installation: disable automatic beacon injection on proxied pages and remove existing beacon tags from themes/plugins/GTM. Automatic injection happens outside CajeerEngine and cannot be blocked by its consent manager.

The consent runtime loads the vendor module from `https://static.cloudflareinsights.com/beacon.min.js?token=...` only on allowed public pages after analytics consent. It reports browser loading status through `CajeerTracking.getStatus().cloudflare`; this is not confirmation of ingestion. CSP includes the fixed vendor script and `https://cloudflareinsights.com` connect origin; the existing `self` source covers same-origin RUM. GTM remains mutually exclusive with direct counters, including this beacon. The vendor owns pageview and performance collection; custom CajeerAnalytics events are not sent to Cloudflare Web Analytics. Other direct trackers can coexist.

On withdrawal, the managed script is removed and the document is reloaded into a denied state; already executing vendor code, in-flight requests and unload beacons cannot be recalled. Static exports must be regenerated after changing tracking configuration. Check the external Cloudflare dashboard for actual data arrival, including Web Vitals which may be reported on visibility change. Exclude externally injected tags before testing refusal.

## Operations and security

- If `INTEGRATIONS_ALLOWED_HOSTS` is nonempty, include `api.cloudflare.com`. Reporting calls are HTTPS-only, fixed endpoint, use the existing DNS-pinned public-only transport, bounded response size, no redirects. API tokens are encrypted; only Bearer headers use them. No arbitrary GraphQL from users.
- Existing `analytics:read`, `analytics:write`, `analytics:run`, and `integrations:secrets` permissions control reports and configuration; `tracking:write` manages the public beacon. Worker payloads contain no credentials.
- Mandatory overview/daily failures fail the job; prior snapshots stay available. HTTP 429 and transient failures use the existing retry mechanism. Optional capability/permission errors mark individual sections unavailable. Provider error bodies are never exposed to the UI.
- CSV uses per-section metric names and values so bytes, HTTP requests, RUM metrics and security events do not share incompatible columns. Reports are not summed across services.
- This release does not add infrastructure alert rules or automatic account/zone discovery. It uses existing source-level sync status; the Cloudflare IDs must be supplied by the operator.

## References

- GraphQL: https://developers.cloudflare.com/analytics/graphql-api/
- Adaptive HTTP requests: https://developers.cloudflare.com/analytics/graphql-api/migration-guides/graphql-api-analytics/
- Limits: https://developers.cloudflare.com/analytics/graphql-api/limits/
- Web Analytics FAQ and beacon/CSP: https://developers.cloudflare.com/web-analytics/faq/
- Web Analytics: https://developers.cloudflare.com/web-analytics/

## Regression

`php tools/cloudflare-analytics-regression.php --require-db` tests the fixed requests, normalization, token validation, schema, policy, persistence, queue and retention using a controlled transport and real PDO SQLite. `python tools/cloudflare-browser-regression.py` requires Playwright + Chromium and exercises actual DOM/consent with intercepted vendor script insertion. Live Cloudflare account acceptance needs valid account credentials, a configured site, and actual external API access.
