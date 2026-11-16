# Alternative Analytics — 1.3.7

Matomo and Plausible are independent tracking and reporting providers. They reuse Consent Management, Analytics Events, Analytics Hub, encrypted credentials and the existing queue/scheduler. No SQL migration is needed when upgrading from 1.3.6. Do not aggregate counts across providers.

## Operator configuration

Only HTTPS endpoints with DNS names, standard port 443, optional simple path prefix, and no credentials, query, fragment or redirects are accepted. Server report requests use the public-only DNS-pinned transport with TLS verification, response limits and no redirects. Private IPs and internal-only installations are not supported. Matomo Cloud and publicly reachable On-Premise, plus Plausible Cloud and compatible self-hosted instances, are supported.

For example, in the environment shared by the web process and worker:

```dotenv
CONSENT_SCRIPT_ORIGINS=https://stats.example.com,https://plausible.example.com
INTEGRATIONS_ALLOWED_HOSTS=stats.example.com,plausible.example.com,plausible.io,api-metrika.yandex.net,analyticsdata.googleapis.com,oauth2.googleapis.com,api.webmaster.yandex.net,www.googleapis.com,searchconsole.googleapis.com
```

Add existing webhook/integration hosts as necessary. `INTEGRATIONS_ALLOWED_HOSTS` is a global outbound policy, not just for analytics. All Matomo and non-cloud Plausible report hosts must be explicitly listed. `https://plausible.io` can be used with an empty global allowlist. Custom browser origins must be in `CONSENT_SCRIPT_ORIGINS`; this setting trusts scripts at that origin, so only use endpoints you administer/trust. Plausible Cloud does not need an explicit browser origin. Do not put API tokens in these variables or the tracking configuration.

## Browser tracking

1. Open `/admin/#/tracking`, enable tracking and enter the exact public website hostnames.
2. Matomo: enter numeric Site ID (e.g. `42`) and the base URL of the Matomo installation, e.g. `https://stats.example.com/matomo` (without `matomo.js` or `index.php`). The runtime loads `matomo.js`, sets `matomo.php`, uses `disableCookies`, `requireConsent` and `setConsentGiven`, and tracks one initial pageview plus manual page views. Cookies are disabled, not a claim of anonymous processing. Ensure Matomo supports consent APIs and accepts cross-origin tracking requests from the website.
3. Plausible: enter the site domain exactly as registered (e.g. `example.com`) and base URL `https://plausible.io` or approved self-hosted base. The runtime posts a browser-originated event to `/api/event` using text/plain JSON, no credentials, no API token. It preserves the real browser network identity rather than routing through a server proxy. An opaque response/HTTP 202 is not proof of ingestion; confirm using the service dashboard.
4. Save; publish again via static export if using a static site. Both providers require Analytics consent. GTM is exclusive with all direct providers. Remove manually installed duplicate tags. Do not use a GTM container to bypass consent.

The existing `CajeerAnalytics.emit('generate_lead',{value:12,currency:'EUR'})` and `pageView('/catalog')` APIs route through configured rules. In `/admin/#/analytics-events`, set Matomo action and Plausible custom-event names; empty disables that destination. `page_view` is fixed, and Plausible's wire event is `pageview`. Matomo events use category `CajeerEngine`, action = mapped name, optional numeric value; other properties are not automatically mapped to Matomo custom dimensions. Plausible receives the existing validated properties. Set up matching event goals in each external service to track conversions; no external goal creation is performed. URL paths/referrers are sanitized by the runtime (query/fragment excluded), so do not embed personal data in paths; UTM attribution is not forwarded. External scripts may independently process information. On withdrawal no new commands are accepted and the runtime reloads after stopping active tracking. Already transmitted events cannot be recalled.

## Reports

Open `/admin/#/analytics-hub`, create an enabled source, choose provider, enter resource, and supply the following JSON in Credential (the token is encrypted, never sent back in API responses or queue payloads):

Matomo resource = numeric Site ID, read-only token with view permission:

```json
{"type":"api_token","endpoint":"https://stats.example.com/matomo","token":"YOUR_MATOMO_TOKEN_AUTH"}
```

Plausible resource = registered site domain, Stats API key:

```json
{"type":"api_token","endpoint":"https://plausible.io","token":"YOUR_PLAUSIBLE_STATS_API_KEY"}
```

Matomo uses POST form bodies with `token_auth`, `VisitsSummary.get`, `Actions.get`, `Referrers.getReferrerType`, `Actions.getPageUrls`, and `Events.getAction`. Plausible uses authenticated POST `/api/v2/query` with `visitors` and `pageviews`, date, source, page and configured goal dimensions. The goal section may be unavailable on installations without that feature; it is marked unavailable rather than zero. Stats API v2 access depends on the plan and installed edition/version. No legacy API fallback is performed. Matomo channel pageviews are unavailable from the chosen API and displayed as missing; page-specific visits and hits are not additive across pages. Event/goal section has its own labels/metrics. The API supplies data in its reporting timezone; the Hub does not infer a timezone when unavailable. Existing 1–90 day window, 20 periods per source, top 100 breakdown rows and hourly scheduler are retained. CSV for the alternative providers uses long format (`metric,value`) to avoid conflating section-specific metrics.

Click Synchronize, then run the existing worker (`php bin/cajeer queue:work --once`) or keep it running with the scheduler. Source API failure preserves the previous successful report. Editing credentials of these providers invalidates stored reports conservatively, to avoid mixing different server/site data. Existing RBAC applies: `analytics:write` + `integrations:secrets` for editing credentials, `analytics:run` for sync, `analytics:read` for reports, `tracking:write` for browser settings. Verify actual provider access and view permissions before production rollout.

## Tests

`php tools/alternative-analytics-regression.php --require-db` exercises normalized API requests, security, existing configuration compatibility, encrypted connections and real queue/SQL integration with controlled provider responses. `python tools/alternative-analytics-browser-regression.py` runs Chromium DOM with intercepted vendor transport. A live-provider check requires real account credentials, provisioned sites, accessible endpoints and permitted traffic. The tests do not fabricate production analytics or assert ingestion by third-party services.

Official references: https://developer.matomo.org/guides/reporting-api ; https://developer.matomo.org/guides/tracking-javascript-guide ; https://plausible.io/docs/events-api ; https://plausible.io/docs/stats-api
