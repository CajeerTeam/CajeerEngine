# Advertising & Conversion Tracking (1.3.9)

## Browser setup

Go to `/admin/#/tracking` as a user with `tracking:write`. Enable tracking, list exact public site hosts, and configure the desired provider IDs. All advertising destinations require **both analytics and marketing consent**. Direct tags are mutually exclusive with GTM; an existing GA4 tag managed by this runtime is shared with Google Ads. Remove externally installed duplicates and disable any automatic event/advanced matching features in the vendor dashboards that would duplicate or exceed your consent policy. Existing independently installed scripts are outside this runtime's control.

| Provider | Configuration | Browser operation |
|---|---|---|
| Google Ads | `AW-...` and one conversion label per enabled event | `gtag('event','conversion', {send_to, value, currency, transaction_id})` |
| Meta Pixel | Numeric pixel ID | `fbq('trackSingle', pixel, name, params, {eventID})` |
| TikTok Pixel | Pixel ID | `ttq.page()` and `ttq.track(name, params, {event_id})` |
| VK Ads / Top Mail.Ru | Numeric counter ID from VK Ads | `_tmr` pageView and reachGoal |
| Yandex Direct | Metrika counter + JavaScript goal ID; connect the goal to Direct in Yandex | `ym(id, 'reachGoal', goal, params)` |

Choose exactly the events that represent distinct completed actions. Default: no advertising conversions. `page_view` loads a page view for Meta, TikTok and VK; Google Ads configuration is separate from its conversion event. Yandex Direct uses Metrika goals, not an advertising-management API. If an analytics Metrika rule targets any configured advertising goal, that target is suppressed on the analytics route and delivered only by the marketing-gated route. Use separate goals for distinct conversions.

| Event | Meta | TikTok | VK goal |
|---|---|---|---|
| `generate_lead` | Lead | Contact | generatelead |
| `form_submit` | Contact | SubmitForm | formsubmit |
| `sign_up` | CompleteRegistration | CompleteRegistration | signup |

Create the corresponding goals/conversion actions in the external dashboards. Each Google Ads label is optional; blank means no Google Ads conversion for that event. Existing event rules still govern whether an event is enabled. Do not emit both `form_submit` and `generate_lead` for the same action unless they are intentionally distinct conversions. No browser `purchase` or `refund` is allowed. The runtime does not infer a completed purchase or lead from a click, URL, or form input.

```js
// Call only after the application confirms the action. Reuse a stable random 32-hex ID for this logical event.
CajeerAnalytics.emit('generate_lead', {value: 25, currency: 'USD'}, {id: '0123456789abcdef0123456789abcdef'});
CajeerAnalytics.getStatus();
CajeerAnalytics.getJournal();
CajeerAnalytics.getAttribution();
```

The optional UTM feature stores first and last touch `utm_source`, `utm_medium`, `utm_campaign` in sessionStorage for the current tab and policy version, only after both consents. Values are limited to 1–80 ASCII letters, digits, dots, underscores and hyphens. Do not put personal data in UTM values. It does not store click IDs or search terms, and does not automatically forward UTM values to providers. The vendor SDKs may process URLs/cookies themselves according to their settings. Withdrawal clears the managed attribution and cookies where accessible, stops managed delivery and reloads the document; completed network requests cannot be recalled. Re-export static sites after changes.

## Direct server conversions

The Integration Center now has `meta-capi` and `tiktok-events`. Create an enabled connection with `integrations:write` and `integrations:secrets`:

- Meta: config `{ "url": "https://graph.facebook.com/v25.0/123456789012345/events" }`, secret = Meta Conversions API access token.
- TikTok: config `{ "url": "https://business-api.tiktok.com/open_api/v1.3/event/track/", "pixel_id": "C1234567890123456789" }`, secret = TikTok Events API access token.
- Use exact permitted destinations in `INTEGRATIONS_ALLOWED_HOSTS` if an allowlist is configured (`graph.facebook.com`, `business-api.tiktok.com`). Set `ANALYTICS_SERVER_TARGETS` to the comma-separated UUIDs of approved connections. Preserve `INTEGRATIONS_KEY` or `APP_KEY`; API tokens and matching data are encrypted with the existing vault.

Only the existing trusted server analytics queue invokes these providers. The ordinary manual test/send API is rejected for them; test with a consented test event and a test pixel. Generic HTTP JSON / Signed Webhook remain available for other server destinations (including custom advertising adapters) but do not accept advertising matching data. Do not use generic webhooks to bypass consent; their consumer is responsible for its own marketing consent checks.

1. Read the current policy version from `GET /api/v1/public/consent`.
2. The **trusted backend** must verify the visitor's current analytics and marketing consent and use a dedicated server API token with `tracking:write` and `integrations:run`. It must keep the token out of browsers and revoke leases when consent changes. A browser cannot grant a server lease itself.
3. `POST /api/v1/tracking/events/server/grants` with `{"version":"<policy version>","ttl_seconds":300,"marketing":true}`. Use the returned grant ID. Existing analytics-only grants cannot authorize advertising providers.
4. Enqueue with `POST /api/v1/tracking/events/server` (consult the exact OpenAPI route names below). Example body:

```json
{
  "grant_id": "<32 hex grant ID>",
  "id": "0123456789abcdef0123456789abcdef",
  "name": "purchase",
  "integration_id": "<UUID>",
  "revision": 1,
  "value": 49.95,
  "currency": "USD",
  "email_sha256": "<64 lowercase hex SHA-256 of normalized consented email>",
  "source_url": "https://site.example/thank-you",
  "user_agent": "<actual visitor user agent>"
}
```

Exact route paths: see `api/openapi.yaml` operations `post_AnalyticsServerGrant`, `post_AnalyticsServerEvent`, `get_AnalyticsServerStatus`, `delete_AnalyticsServerGrant`. The backend must confirm the actual purchase or lead and use the same ID for the same conversion across retries and browser/server events; do not supply a fake customer identifier. Normalize email with trim + lowercase before hashing on the trusted backend. Hashing is pseudonymization, not anonymization. Source URL must be HTTPS on an allowed tracking host, without credentials, port, query or fragment, and must not contain personal data. Only send the real visitor user agent; it is encrypted along with the matching key and source URL in queue/lease storage. Configure access, retention and backup policies accordingly. Existing queue records follow the installation's retention policy; expired lease state is pruned on subsequent access. Use a short consent lease (maximum 900 seconds).

Supported server events: `purchase`, `generate_lead`, `form_submit`, `sign_up`. Meta maps purchase to Purchase, TikTok to Purchase; the lead/form/signup mappings match the browser above. Each event is delivered separately. Meta requires `events_received >= 1` and no API error; TikTok requires `code == 0`. Provider bodies and credentials are not exposed in diagnostics. HTTP errors and unsuccessful API receipts are recorded as failed and retried by the existing worker with stable event ID and timestamp; the worker checks the grant, target revision and policy before delivery. A provider's acceptance is not proof of attribution or reporting. Browser/server deduplication requires the same event name, pixel, and event ID plus provider support. No browser purchase is sent by this runtime. Google Ads, VK and Yandex Direct use browser conversions; direct server conversion APIs for them are not implemented.

## Diagnostics and verification

- `/admin/#/tracking` configures the providers; `/admin/#/analytics-events` displays event outcomes. Enable optional server diagnostic collection there. Browser entries distinguish queued (SDK loading), submitted (command passed to SDK), and failed. They do not establish external receipt. The journal contains IDs, names, outcomes and timestamps only, never matching data or campaign values.
- `/admin/#/integrations` displays the latest actual server operation status. The server grant status API exposes queued/failed/delivered/cancelled. Real delivery requires a running queue worker: `php bin/cajeer queue:work --once`.
- Verify browser Network and the platform's test events/debug tools with real account IDs; verify server API receipts and provider dashboards. Vendor-controlled automatic events, consent behavior, remote CSP dependencies and regional availability depend on external configuration. Test tags with the actual site before deploying.
- The browser regression exercises Chromium DOM with vendor loading intercepted; PHP regression uses controlled HTTP responses and the real FileQueueDriver/QueueWorker. Neither asserts live vendor account acceptance.

Official references: [Google Ads conversions](https://developers.google.com/tag-platform/devguides/conversions), [Meta Pixel](https://developers.facebook.com/docs/meta-pixel/), [Meta Conversions API](https://developers.facebook.com/docs/marketing-api/conversions-api/), [TikTok Events API](https://business-api.tiktok.com/portal/docs/events-api-for-web/v1.3), [Top Mail.Ru goals](https://top.mail.ru/help/en/settings/goals).
