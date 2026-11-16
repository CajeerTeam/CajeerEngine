# Migration Center & Discovery (1.4.1)

This release adds a working discovery wizard at `/admin/#/migration`. It **does not import or modify target CMS records**, execute SQL dumps, copy executable files, or run source plugins. Planning and migration execution are later releases.

## Source access

Preserve `APP_KEY` / `INTEGRATIONS_KEY` (32-byte base64 key). Restrict access to `storage/app/migration`. Configure the server environment (or `.env` via normal bootstrap):

```dotenv
MIGRATION_DB_ALLOWED_ENDPOINTS=127.0.0.1:3307,10.0.0.20:3306
MIGRATION_HTTP_ALLOWED_HOSTS=old.example.com
MIGRATION_DB_TLS_CA=/etc/ssl/certs/source-db-ca.pem
```

Empty allowlists deny connections. DB endpoints are literal IPv4 addresses and allowed port pairs. Use an operator-controlled private SSH/VPN tunnel or verified TLS. Public DB addresses require a CA and certificate verification (certificate must cover the address). Install `pdo_mysql` for database discovery, `curl` for REST and `xmlreader` for WXR. Set PHP upload/post limits above 8 MiB plus multipart overhead. No new target SQL migrations.

Create a SELECT-only account on the source database (no FILE, EXECUTE, write or administrative grants). Discovery uses a read-only transaction, with queries capped at five seconds by the source server; use a restored replica for large sites. Intended profiles: MySQL 5.7+ and MariaDB 10.11+. Specify prefix if multiple installations are detected. Do not point at the target CMS. Exact CMS release compatibility is not inferred from table schema.

REST uses `/wp-json/wp/v2/` under configured HTTPS base URL. Optional WordPress Application Password uses Basic Auth over verified HTTPS. No redirects, private destinations or arbitrary API routes. `X-WP-Total` counts visible records; if absent, a full first page is a lower bound. Unknown collections and time budget exhaustion are marked unavailable, not zero. Custom post types and full user lists require DB/WXR. Only pretty REST URLs are supported.

## Wizard

1. Create a project (DLE DB, WordPress DB, REST or WXR), enter source details.
2. Open the project and run discovery, or upload an uncompressed WXR 1.0–1.2 XML export (8 MiB max). DTD/external entities are disabled, malformed XML refused; referenced files are not downloaded.
3. Inspect actual counts, types/statuses, bounded field inventory, schema and compatibility warnings. Export JSON. WXR authors are export authors, not full accounts; attachments are not file bytes. DLE fields are sampled from up to 1000 records; media file counts and exact DLE version cannot be inferred from database alone. WordPress DB schema revision is not the application release.
4. Re-run using saved credentials/WXR. Failed re-scan retains last successful report and encrypted WXR. Reconfigure to replace source (clears report and WXR). Delete removes credentials and WXR. Stale revisions return 409.

## Permissions and API

`migration:read` reads reports; `migration:write` + `integrations:secrets` creates/replaces source configuration; `migration:write` deletes; `migration:run` scans; upload requires `migration:run` + `migration:write`. Browser writes use existing CSRF. Projects are global administrative workspaces; grant scopes only to trusted operators. Credentials are write-only; reports contain schema names and aggregates, not content bodies or passwords.

- GET/POST `/api/v1/migration/projects`
- GET/PUT/DELETE `/api/v1/migration/projects/{id}`
- POST `/api/v1/migration/projects/{id}/scan` JSON `{"revision":1}`
- POST `/api/v1/migration/projects/{id}/wxr` multipart `file` and `revision`

Example create:

```json
{"name":"Legacy site","source":"wordpress-rest","config":{"url":"https://old.example.com"},"credential":{"username":"migration-reader","password":"APPLICATION_PASSWORD"}}
```

DB config: `host`, `port`, `database`, `prefix` (empty = auto), `charset` (`utf8mb4`, `utf8`, `cp1251`); credential: `username`, `password`. WXR uses empty config/credential objects. PUT supplies full config and current revision; DELETE and scan require current revision. Completed scan returns `discovered` or `failed` with sanitized error; failed source scan is a persisted outcome.

## Persistence and deployment

Maximum 25 projects. Metadata and encrypted WXR files live under `storage/app/migration` with restricted permissions and atomic writes. WXR is encrypted separately, with metadata committed after the artifact. A crash between writes can leave an unreferenced encrypted artifact; subsequent mutation removes it. Back up directory and key together; project operations take the shared application lease so exclusive backup/update drains registry writes; multiple nodes require shared filesystem. Discovery runs synchronously with bounded requests, not a bulk migration worker; use a suitable FPM timeout or smaller source replica. No blanket support for all DLE versions, WordPress plugins or multisite layouts is claimed.

## Regression

`php tools/migration-discovery-regression.php` checks storage, REST, validation, plus XMLReader when available. `--require-xml` and `--require-mysql` make real XML and MariaDB fixture checks mandatory. The latter uses `MIGRATION_TEST_MYSQL=1` and `MIGRATION_TEST_MYSQL_PASSWORD` for a disposable local database. Release CI includes these and Chromium admin wizard regression.
