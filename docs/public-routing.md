# Public Routing Runtime (1.1.7)

## Upgrade

Back up your installation before applying the patch. Run `php bin/cajeer migrate`, then `php bin/cajeer routing:rebuild --json` and `php bin/cajeer routing:check --json`. The installer and signed updater initialize and verify the route index as part of their final checks. Do not serve the upgraded code before migrations complete. For older sites, repair duplicate public paths and missing/cyclic parents before completing the upgrade. A failed rebuild returns a nonzero exit status. Use entry UUIDs (not ambiguous slugs) when editing localized content. Existing preview tokens referring to slugs must be recreated.

## Configure a site

Set `APP_URL` to the canonical HTTP(S) origin (no path, query or fragment), e.g. `https://site.example`. The origin is not inferred from the incoming Host header. `cms:bootstrap --demo-home` creates a demo homepage if needed. Otherwise create a published `pages` entry with `slug=home` or an explicit `data.path=/`. The theme's `home_slug` selects a top-level page by slug; a published explicit root takes precedence. No home is shown when none is published.

In Admin → CMS set the default locale, enabled locales, and routing strategy. `none` leaves paths unchanged and requires unique published URLs across all languages. `prefix` prefixes non-default languages (e.g. `/en/about`) and leaves the default language at `/about`. Each language has its own homepage. A disabled locale is hidden in prefix mode. This release supports one canonical origin; domain-per-locale and automatic language detection are not implemented.

Create a page with `data.parent_id` equal to its parent's UUID. The effective path is the parent's path plus the child's slug, even if the child contains an old explicit `data.path`. A root page can use a custom nested path. Parents must be pages of the same language; cycles and excessive depth are rejected. A parent with children cannot be deleted until they are moved/deleted. A content type with entries cannot be deleted. The parent and all ancestors must be published for a descendant to be publicly visible. A conflicting published path rejects the write and leaves the previous content intact. Changes to a published path create historical 301 aliases for that page and affected descendants; each alias points directly to the latest route. Root is never an alias. Aliases are removed when the target is unpublished/deleted, and a new live page can reclaim an old path.

## Route resolution

System routes (API, admin, installer, upgrade, preview, uploads and other reserved namespaces) take precedence and cannot be registered as content paths. Canonical paths use percent-encoded UTF-8, no traversal, encoded separators, control characters or duplicate/trailing separators. Live page routes win over explicit redirect rules, then historical aliases. A noncanonical spelling of a live path returns 308; historical aliases return 301. Normalization and alias redirects preserve query strings. Explicit rules use their configured target as written (including its own query). Rules support exact paths and status 301, 302, 307, 308 or 410. 410 has no target. Local and same-origin loops are rejected, including chains. External targets must be HTTP(S). Explicit rules have hit counters. Wildcard/regex redirects are intentionally not supported.

Set `error_404_path` and `error_410_path` to published CMS pages to customize error responses. They retain their HTTP error status and are excluded from the sitemap. Missing or unpublished error templates use the built-in error response. Menu items linked by entry UUID use the current indexed public URL; unpublished entries are hidden. Direct URLs remain as configured.

Sitemap URLs are based on the configured canonical origin. Drafts, hidden descendants, aliases, error pages, noindex and noncanonical entries are excluded. Translation groups produce hreflang links; large sitemaps use 500 URLs per document with `?page=N`. Preview token URLs are independent of the public path and return private/no-store/noindex responses. Recreate old tokens that used slugs.

## Storage and consistency

The three SQL migration variants create `ce_public_route_state` and `ce_public_routes`. The unique SHA256 path key makes hot lookups indexed, with an exact path comparison, and the public entry is retrieved by an indexed join that rechecks publication. Content/theme repository writes acquire a route lock and update content and routes inside one SQL transaction; the import engine batches its mutations under the same gate and backup/recovery procedure. Editorial changes rebuild the route graph in memory and write only changed index rows. This is optimized for read-heavy sites, not constant-time writes to very large trees. The current release targets single-node deployments with shared local runtime permissions, `CONTENT_STORAGE=database` and `CMS_STORAGE=database` for production. File mode is for demo/small sites: its derived JSON index is checked against source metadata, and writes use snapshots and a local lock. Do not edit production repository files or tables directly; after a manual repair run `routing:rebuild`.

`GET /api/v1/cms/routes?limit=100&offset=0` requires `content:read`. Admin content lists include `public_path`. See OpenAPI and SDKs for the updated operation.

## Tests

`php tools/routing-regression.php` tests the real file repositories and renderer, route transitions and validation. `php tools/routing-regression.php --database` runs the same tests with PDO SQLite and all SQL migrations. `tools/release-smoke.sh` tests the actual installed dist over HTTP, including nested URLs, aliases and publication. The release workflow runs both the SQLite and PostgreSQL smoke profiles. MySQL migration is supplied; run an integration test against your supported MySQL/MariaDB installation before certifying that profile. The SQL and HTTP test profiles require actual PDO drivers and Composer dependencies.
