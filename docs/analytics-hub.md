# Analytics Hub Core — 1.3.5

Analytics Hub reads aggregate statistics from real provider APIs. It does not change the visitor-side tracking configuration or collect new visitor identifiers. Connections are independent of HTTP webhook integrations but reuse their SecretVault key, outbound transport, global enable switch and host allowlist. Reports are never summed across providers.

## Upgrade and operations

1. Back up the installation, DB and encryption key (`INTEGRATIONS_KEY`, or `APP_KEY` fallback). Apply the 1.3.5 files and run `php bin/cajeer migrate` (migration `0015_analytics_hub.sql`, for SQLite, PostgreSQL or MySQL/MariaDB). Do not overwrite existing `.env` or runtime storage.
2. Install PHP cURL, OpenSSL and the applicable PDO driver. Ensure outbound HTTPS and DNS are available to the worker. `INTEGRATIONS_ENABLED` and `ANALYTICS_REPORTING_ENABLED` must be true. If `INTEGRATIONS_ALLOWED_HOSTS` is configured, include `api-metrika.yandex.net`, `analyticsdata.googleapis.com` and, for GA4 token exchange, `oauth2.googleapis.com`.
3. In `/admin/#/analytics-hub` add a source. Report IDs are numeric counter/property IDs, not GA4 Measurement IDs (`G-…`) or GTM containers. Credentials are a JSON object, write-only; the UI never receives decrypted credentials. For a service-account key extract only the two listed fields from the downloaded Google JSON.
4. Press **Синхронизировать**. The request enqueues a job; run the existing worker (`php bin/cajeer queue:work --once`) or use the installed worker service. `php bin/cajeer scheduler:run` enqueues enabled sources each UTC hour. Restart workers after upgrade. Jobs use the default queue, existing retries/backoff and failure diagnostics.
5. Open **Отчёты** and refresh after the job finishes. Default synchronization covers the last 30 completed UTC calendar dates (the vendor interprets dates in its own reporting timezone). Pick an exact 1–90 day interval to synchronize or read it. The dashboard shows totals, a daily chart, channels and landing pages (top 100 by visits/sessions); CSV exports the actual snapshot. No data is represented as absent, not fabricated zeros.

### Yandex Metrika

Use a Yandex OAuth token granted access to the counter with the `metrika:read` scope. Credential:

```json
{"type":"oauth_token","token":"YOUR_YANDEX_TOKEN"}
```

An optional numeric Goal ID adds `ym:s:goal<ID>reaches`. Totals come from a separate undimensioned Reporting API call. Reports use `ym:s:users`, `ym:s:visits`, `ym:s:pageviews`, `ym:s:date`, `ym:s:lastTrafficSource`, `ym:s:startURL`. The provider may sample despite requesting full accuracy; sampling information is retained. Renew revoked/expired tokens in the source editor.

### Google Analytics 4

Enable Google Analytics Data API in the Google Cloud project. Grant the service account Viewer access to the selected GA4 property (not merely the Cloud project). Use the numeric Property ID.

```json
{"type":"service_account","client_email":"SERVICE_ACCOUNT_EMAIL","private_key":"-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"}
```

Or supply a Google OAuth refresh token obtained with `https://www.googleapis.com/auth/analytics.readonly`, together with the corresponding OAuth client ID and secret:

```json
{"type":"refresh_token","client_id":"CLIENT_ID","client_secret":"CLIENT_SECRET","refresh_token":"REFRESH_TOKEN"}
```

A pre-issued Google OAuth access token is also supported as `oauth_token` (expires; use service account or refresh token for unattended jobs). GA4 issues an access token per synchronization. `activeUsers`, `sessions`, `screenPageViews`, `keyEvents` are read via `properties.runReport`; the dimensions are date, session default channel group and landing page. Thresholding, data loss and sampling flags are shown. Views include app screens in mixed web/app properties; users are not additive across days or channels.

## Security and retention

- `analytics:read` lists and reads reports; `analytics:run` enqueues; `analytics:write` together with `integrations:secrets` creates/updates/deletes sources. Existing administrator wildcard access works. Re-login after changing custom-role permissions. Browser mutations require CSRF.
- Credential encryption is authenticated and bound to source ID, provider and resource ID. Editing provider/resource requires a new credential; changing resource or goal clears incompatible historical snapshots. Credentials never enter the queue, report JSON or audit metadata. Protect DB backups and encryption keys separately.
- Fixed provider URLs and existing public-IP/TLS/DNS-pinning transport prevent arbitrary outbound destinations. Requests have bounded time/body size. API response/error bodies are not returned or logged. Provider HTTP error codes are retained; HTTP 429 and temporary failures use queue backoff.
- SQL lease and revision checks prevent stale jobs overwriting edited sources. Only completed reports replace snapshots; failed jobs preserve the last successful report. Up to 20 latest exact-period snapshots per source, with a maximum of 50 sources. Automatic daily roll-forward can evict older periods; export needed historical data. Disable or delete a source to stop future requests; an already-running external request cannot be cancelled but cannot publish after an edit/delete.
- Landing page query strings/fragments and URL userinfo are removed before storage; paths can still contain personal data. Avoid PII in tracked URLs and restrict report access. This feature reads already-collected aggregates and does not bypass the public consent gate.

## Scope

This release implements Reporting/Data API reporting, not vendor account discovery, Google Admin API, Metrika Management API, interactive OAuth authorization-code flows, realtime, cross-provider totals, full high-cardinality pagination or arbitrary segmentation. Top rows are explicitly truncated where applicable. Custom dates and separate snapshots allow manual period comparisons; automated comparison and filters are planned. No actual account credentials are included. Test with your own read-only resources before production rollout.

Official API references: https://yandex.com/dev/metrika/en/stat/openapi/data and https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport.
