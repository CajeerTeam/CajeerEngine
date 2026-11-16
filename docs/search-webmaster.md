# Search & Webmaster — 1.3.6

Upgrade from 1.3.5: back up database, `.env`, `storage/` and the existing `INTEGRATIONS_KEY`/`APP_KEY`; apply `php bin/cajeer migrate`, restart queue workers and scheduler. Migration 0016 widens the resource identifier in PostgreSQL/MySQL; SQLite supports longer text already. Existing analytics connections and snapshots remain intact.

Open `/admin/#/analytics-hub`, add an active source, then select a period and request synchronization. The existing `analytics.report_sync` job runs through `php bin/cajeer queue:work --once`; the scheduler enqueues the last 30 completed UTC days hourly. The report is saved only after a successful synchronization; errors leave previous successful snapshots intact. No browser tracking or visitor data collection is added by this release.

## Connections

| Provider | Resource | Credentials |
|---|---|---|
| Yandex Webmaster | `https:example.com:443` (exact verified Host ID) | `{"type":"oauth_token","token":"..."}` |
| Google Search Console | `https://example.com/` or `sc-domain:example.com` (exact verified property) | access token, refresh token or service account |

Yandex: create an OAuth application with Webmaster read access (`webmaster:hostinfo`); sitemap submission requires `webmaster:hostmanage`. The API discovers the token owner's user ID automatically. Copy the verified Host ID from Webmaster; property verification and OAuth token acquisition occur outside CajeerEngine.

Google: enable Search Console API and grant the credential access to the verified property. Use `{"type":"oauth_token","token":"..."}`, `{"type":"refresh_token","client_id":"...","client_secret":"...","refresh_token":"..."}` or `{"type":"service_account","client_email":"...","private_key":"-----BEGIN PRIVATE KEY-----\n..."}`. Read operations use `https://www.googleapis.com/auth/webmasters.readonly`; sitemap submission needs `https://www.googleapis.com/auth/webmasters` and appropriate property permissions. Service account assertions request the appropriate scope for the operation. OAuth access tokens expire; refresh tokens or service accounts are preferred for background sync. Interactive OAuth callback and verified property selection wizard are not included.

If `INTEGRATIONS_ALLOWED_HOSTS` is configured, allow `api.webmaster.yandex.net` for Yandex and `www.googleapis.com` for Google reports/sitemaps, `searchconsole.googleapis.com` for URL inspection and `oauth2.googleapis.com` for Google token renewal. The HTTP client validates DNS, pins a public IP, enforces TLS, disables redirects, and bounds response sizes. Credentials remain encrypted at rest and are never returned by the API. Read requires `analytics:read`; source edits require `analytics:write` + `integrations:secrets`; synchronization requires `analytics:run`; inspection and sitemap submission require `analytics:write` + `analytics:run`. Browser sessions require CSRF. Actions are audited without credential or URL payloads.

## Reports and limitations

- Google: final web-search data, total, day, query, page, device and country dimensions; each detailed section is bounded to 100 rows. Search Analytics may omit anonymized queries and other rows. Totals come from a separate provider query; row counts must not be summed across dimensions. Google dates use Pacific time.
- Yandex: history and popular queries for selected dates; totals use available daily data, CTR = clicks / impressions, mean position weighted by impressions. Missing metrics remain null. Actual popular-query dates are shown where supplied. URL monitoring only covers the recent two-week window, with partial date labels and a 100-item provider sample; historical URL reports outside that window are omitted. Dates use Moscow time. API access/data availability varies by site; provider failures are visible in sync status.
- Sitemap diagnostics and Yandex indexing samples describe the state when fetched, not a historical period. Sitemap list is capped at 100. Yandex samples are not a complete index. Google URL inspection is an on-demand check of the indexed version, not a live test or indexing request. Sitemap submission does not guarantee crawling or indexing. After submission, sync again to refresh diagnostics.
- URL query/fragment portions are removed from saved page and sitemap labels to limit disclosure; different URL variants can therefore display the same label, but their metrics are not silently merged. Search terms may contain personal data: restrict report access and exports. CSV includes raw CTR ratios and nulls as empty cells, and only performance sections, not webmaster diagnostics. No cross-provider metric aggregation.

API: existing `/api/v1/analytics/reports` CRUD, `/sync`, `/data`; new `POST /api/v1/analytics/reports/{id}/webmaster` with `{"revision":1,"action":"inspect","url":"https://example.com/page"}` or `submit_sitemap`. Only URLs within the configured property are allowed. A successful submission returns `accepted:true` and does not imply indexing. Use actual provider credentials for live acceptance.

Official reference: [Yandex Webmaster API](https://yandex.com/dev/webmaster/doc/en/concepts/getting-started), [Google Search Analytics](https://developers.google.com/webmaster-tools/v1/searchanalytics/query), [Sitemaps](https://developers.google.com/webmaster-tools/v1/sitemaps/list), [URL Inspection](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect).

Tests: `php tools/search-webmaster-regression.php --require-db` (requires PDO SQLite) tests provider wire formats, OAuth, scope isolation, URL validation, sitemap submission, inspection and actual queue/SQL persistence with controlled responses. The existing HTTP regression tests route access, CSRF and validation. External account access is needed for live API acceptance.
