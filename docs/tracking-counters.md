# Tracking & Counters — CajeerEngine 1.3.3

## Installation and configuration

Apply the changed-files archive over 1.3.2, preserving `.env`, `storage/`, and the database. No SQL migration is required. Open `/admin/#/tracking` (Счётчики). Enter the exact public hostname(s), such as `example.com, www.example.com` (ASCII/punycode, no scheme, port or wildcard). Enable tracking and publish the settings. The public API returns only non-secret identifiers and configuration. The admin API is protected by `tracking:read` and `tracking:write`; writes use session CSRF or a scoped API token and optimistic revision checks. The current settings are saved atomically in `storage/app/privacy/tracking.json`, covered by backups; share storage with reliable locking on multi-node installations. No credentials are needed for these client-side tags.

Choose one mode:

- Direct Metrica and/or GA4: enter a numeric Metrica counter ID and/or a `G-...` measurement ID. Metrica loads `https://mc.yandex.ru/metrika/tag.js`; GA4 loads `https://www.googletagmanager.com/gtag/js`. Both require analytics consent. Each page initializes once and sends one explicitly controlled page view. Metrica link tracking, click maps and Webvisor are disabled. GA4 automatic initial page view and Google advertising signals are disabled; DebugView is optional. Configure cross-domain measurement and enhanced measurement in the GA property separately; disable automatic history-change pageviews if calling manual `pageView` on a SPA. Remove pre-existing direct or GTM tags from themes/plugins. The runtime detects already present matching script URLs but cannot police independent scripts installed elsewhere.
- GTM: enter `GTM-...` instead of both direct IDs. The actual container script is loaded only when analytics, marketing and functional categories are all granted; this is intentional because a remotely edited container can activate arbitrary optional tags. Configure only a trusted web container, use built-in consent controls, and keep its tags within your published privacy policy. The runtime pushes standard `gtm.js` bootstrap plus `ce_page_view` and `ce_<event>` events. In GTM, create a Google tag and Metrica tag as desired, and choose either a standard initial-page trigger or `ce_page_view` with manual pageview settings, not both. Custom HTML, arbitrary scripts, advertising endpoints and preview/debug domains may be blocked by CSP; this release does not enable `unsafe-eval` or `unsafe-inline` for scripts. The operator must audit the remote container and any explicitly added CSP origins.

## Browser API

The public consent runtime exports `window.CajeerTracking`:

```js
// Call after the user's consent is granted. A false return means no active provider accepted the call.
CajeerTracking.pageView('/articles/next'); // same-origin only; consecutive duplicate paths ignored
CajeerTracking.event('generate_lead'); // GA4 event, Metrica reachGoal, GTM ce_generate_lead
CajeerTracking.event('qualified_lead', {value: 10, currency: 'USD'});
CajeerTracking.getStatus(); // {ga4: {id, status, reason}, ...}
```

`pageView` removes query strings and fragments, excludes service routes, and does not send a separate title. It supports explicitly triggered virtual page views; automatic page views are generated for normal full-document CMS navigation. The event API accepts names of up to 40 ASCII characters with letters, numbers and underscores (letter first), excluding reserved prefixes and `page_view`. Optional parameters are a finite numeric `value` and three-letter `currency`. Do not put personal information into event names or page paths. Create the matching goal in Metrica and register relevant custom events in the other vendor consoles. A `true` return means a command was queued, not that a service ingested it. Browser blockers, network errors and invalid remote resources can prevent ingestion.

The status event `cajeer:tracking-status` carries a snapshot of the loaded providers. `loading`, `loaded`, `error`, and `stopped` describe library loading, not reporting success. Error reasons include `existing-tag`, `load-failed` and `initialization-failed`. Inspect the browser Network tab and vendor DebugView/Tag Assistant/Metrica diagnostics for actual acceptance. `loaded` alone is not evidence of event delivery. This release does not read statistics via APIs, send server-side measurement events or implement the full event collector planned for 1.3.4.

## Privacy, CSP and static sites

The 1.3.2 consent manager continues to default to denied. No managed vendor script, pixel fallback or GTM iframe is inserted before consent. Google consent defaults are queued locally first. Tracking configuration is included in the version hash, so publication invalidates old decisions and a new consent is required. Dynamic pages fetch current policy independently of HTML cache. Revocation disables GA4, asks a loaded Metrica counter to stop, removes managed scripts, clears supported first-party cookies via consent runtime and reloads the page. A remote library may already have sent or queued data; revocation does not erase remote data or guarantee immediate cancellation of in-flight requests. For cross-tab revocation the existing storage listener reloads other active tabs. Providers may collect additional information automatically; configure the vendor account appropriately, disable enhanced measurement where needed, avoid sensitive URLs and comply with applicable policy requirements. The engine cannot make an arbitrary GTM container privacy-compliant.

For dynamic public HTML the security middleware automatically permits fixed vendor script/connect origins only for configured hosts with enabled counters. The existing `CONSENT_SCRIPT_ORIGINS` setting remains an operator-controlled override for other approved origins. Admin, preview and service routes retain their prior policy. Do not use an additional reverse-proxy CSP that blocks required provider origins. Reload a page after changing counter settings so its CSP is refreshed as well. For a static site, run the existing static export again after configuration changes, and deploy the new snapshot and assets. The browser uses the embedded configuration without an API. The static host is responsible for CSP headers: allow the appropriate vendor origins documented below and the same-origin runtime. Old static exports cannot receive dynamic settings or policy revocation updates from the server; replace or withdraw them when the policy changes.

Official vendor documentation:

- https://yandex.com/support/metrica/en/code/counter-initialize
- https://yandex.com/support/metrica/en/code/install-counter-csp
- https://developers.google.com/analytics/devguides/collection/ga4/views
- https://developers.google.com/tag-platform/security/guides/csp

## Verification

```sh
php tools/tracking-regression.php
php tools/consent-regression.php
python tools/tracking-browser-regression.py
python tools/tracking-browser-regression.py --network
php tools/tracking-http-regression.php # requires Composer vendor and PDO SQLite
php bin/cajeer api:lint --strict
php tools/generate-api-sdk.php --check
node admin/scripts/publish-admin-assets.mjs --check
```

Browser regressions use real Chromium with controlled vendor scripts, not real vendor accounts. For release acceptance, verify a permitted production/test domain with real valid IDs: no requests before consent, actual pageviews/events after consent, no duplicate initial event, and no new managed scripts after withdrawal. The release workflow includes the network-intercepted test and the actual HTTP middleware authorization test in the dist build pipeline.
