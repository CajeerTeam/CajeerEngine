# Consent Management — CajeerEngine 1.3.2

## Administration

Open `/admin/#/privacy` (Приватность). Administrators can set the Russian and English banner title and description, the local path of a published privacy policy page and the decision lifetime (1–365 days, default 180). Publishing increments the policy revision and invalidates prior decisions. The policy is active immediately on dynamic pages and is included in the next static export. The public site shows a persistent Privacy preferences control; visitors can reject all, accept all, select individual categories or withdraw later.

Required scopes: `privacy:read` to read settings, `privacy:write` to publish; existing wildcard administrators retain access. The browser admin uses its authenticated session and CSRF token. Policy updates are audited with actor and revision, not visitor data. The settings are stored atomically in `storage/app/privacy/policy.json` with an exclusive lock, optimistic revision checking and private file permissions. This file is covered by the existing backup/restore mechanism. For a multi-node deployment share the policy store with reliable locking and configure the same allowed origins on every node; otherwise deploy to a single application node. No SQL migration is required.

## Visitor consent

Categories: `necessary` (always true), `analytics`, `marketing`, `functional`. Optional categories are false before an explicit choice. The browser stores the current decision, policy fingerprint, timestamp, expiry and a rolling history (at most 20 choices over the preceding 365 days) in origin-scoped `localStorage`, key `ce.consent.v1`. No visitor identifier, IP, or consent history is sent to the server. The data is not a server-signed consent receipt and is not a centralized compliance register. If persistence is unavailable, a decision is session-page-only. Clearing storage or expiration invalidates consent; changing policy or its permitted origins invalidates prior decisions. Tabs on the same origin react to storage changes. Dynamic pages obtain the current policy via `GET /api/v1/public/consent` before enabling tags, recheck on visibility changes and periodically, and fail closed if the request fails. During an outage users can still clear their stored decision, but cannot grant new consent until the policy is available.

The UI is localized RU/EN according to the page language, supports keyboard controls, and uses text content (no HTML injection). A policy page can be created using the existing CMS and linked in the admin UI. The permanent preference control is the visitor privacy center; it does not require a separate CMS route.

## Gate optional scripts in themes and extensions

Do not add live `<script src>` tags, preload/preconnect links, tracking images, iframes or tracking JS to a page when the resource requires consent. For trusted theme templates, declare a script as an inert descriptor, without a `src` attribute:

```html
<script type="text/plain" data-ce-consent="analytics" data-ce-src="/extensions/my-theme/analytics.js"></script>
<script type="text/plain" data-ce-consent="marketing" data-ce-src="https://trusted.example/pixel.js"></script>
```

The runtime activates descriptors only after the matching category is granted. It creates real script elements with the configured URL, supports optional `data-ce-integrity` for SRI, and checks the same-origin or exact HTTPS origin allowlist. Unknown categories and unsafe URLs remain inert. Newly inserted descriptors are also evaluated. No arbitrary inline JavaScript is accepted in the admin policy editor.

External script origins are operator-controlled. Configure the comma-separated list in `.env`, e.g.:

```dotenv
CONSENT_SCRIPT_ORIGINS=https://trusted.example
```

The public HTML CSP permits these exact HTTPS origins for `script-src` and `connect-src`; admin/preview CSP stays restricted to self. If a service needs additional script/connect origins, add only the known trusted origins and review its behavior. The allowlist is not a network firewall: trusted scripts may run their own code, and images, iframes, CSS and arbitrary plugin scripts are not automatically consent-managed. Never embed a tracker as a live resource before consent. Do not deploy a trusted tag that silently activates another category. Existing server-side webhooks and integration operations are unchanged and must not be used for visitor tracking unless a later integration explicitly enforces consent.

For custom trusted code, use the browser API:

```js
if (window.CajeerConsent?.allows('analytics')) {
  // send an optional analytics event
}
document.addEventListener('cajeer:consent-change', ({detail}) => {
  if (!detail.categories.analytics) {
    // stop any analytics timers or subscriptions owned by this code
  }
});
// window.CajeerConsent.open();        // show preferences
// window.CajeerConsent.withdraw();    // revoke optional categories
// window.CajeerConsent.getState();    // local decision metadata
```

`allows()` returns false for optional categories until the policy is ready and a valid explicit decision exists. On withdrawal the runtime emits a change event, removes the managed script elements, clears common first-party analytics/marketing cookies (e.g. `_ga`, `_ym_*`, `_fbp`, `_gcl_*`) and functional cookies with the `ce_optional_` prefix, and reloads documents with already activated scripts. It does not delete essential CMS/auth cookies. Cookie cleanup is best-effort for visible cookies, current path/ancestor paths and accessible domains; it cannot delete HttpOnly or third-party cookies, undo previous transmissions, or guarantee shutdown of arbitrary already-executed code. Additional vendor cleanup must be implemented by provider-specific adapters when they are added.

Google Consent Mode v2 is implemented in basic/default-denied form: `analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage` and `security_storage` are queued locally in `dataLayer` and updated with decisions before managed Google scripts load. No Google tracking script or consent ping is sent by Consent Management alone. Real GA4/GTM/Metрика counters belong to 1.3.3.

## Cache, static export and upgrades

The runtime markup is appended after the shared server HTML cache is read. A visitor's consent is never embedded into a cached response. Preview and admin pages exclude the runtime. Search and fallback public error pages receive it. Static export includes the JS/CSS assets and a fixed policy snapshot, without calling the dynamic API. Re-export/redeploy the static site whenever policy or allowed origins change. Configure the static host's own CSP to match the allowed origins. Purge external HTML/CDN caches on upgrade so old theme markup cannot bypass the new consent gate.

Apply the 1.3.2 changed-files archive over 1.3.1, preserve `.env` and `storage/`, and run the usual upgrade verification. Consent assets are stored in `resources/consent` and served from fixed `/assets/ce-consent/` URLs by the front controller (GET/HEAD, MIME type, ETag). This preserves the 1.3.1 updater’s existing `resources/` allowlist; static exports copy the same bytes into their public asset directory. All new assets are included in source/dist release policy. The new policy defaults appear without an install step. To verify:

```sh
php tools/consent-regression.php
php tools/rendering-regression.php
php tools/rendering-regression.php --database
php tools/generate-api-sdk.php --check
node admin/scripts/publish-admin-assets.mjs --check
php tools/ci-check.php
# Chromium with Playwright installed:
python3 tools/consent-browser-regression.py
python3 tools/consent-dom-regression.py
```

## Scope and limitations

Consent Management is a functional privacy control, not a guarantee of compliance with every jurisdiction. The operator is responsible for the actual cookie inventory, policy text, legal basis, retention requirements and third-party terms. Anonymous browser-local decisions and configurable policy are supported; a server-side evidence register, authenticated cross-device preference sync, complete provider-specific cookie cleanup, and provider-specific tracking are outside 1.3.2.
