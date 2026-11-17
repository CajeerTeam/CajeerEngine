# SEO & URL Migration — CajeerEngine 1.4.7

This is a **real post-content migration step**, not a declaration or preview. It reads existing DLE/WordPress migration receipts, validates the existing destination routes and imported source records, writes read-only SEO metadata, creates verified 301 rules, rewrites matching internal links, and records an immutable SQL completion receipt. The command holds the exclusive operation lock and uses the ordinary route/index transaction.

## Before running

1. Back up the target database and `storage/`, including the original encrypted WXR/project credentials and `APP_KEY` / `INTEGRATIONS_KEY`.
2. Install the patch over 1.4.6 and run `php bin/cajeer migrate`. Migration `0022_seo_url_migration.sql` is required for SQLite, PostgreSQL and MySQL. Use database-backed CMS/content storage.
3. Complete a DLE or WordPress content migration and (optionally) the separate media migration. The source DB/REST site or encrypted WXR must still be available for fresh SEO inventory reads.
4. Verify **actual** legacy permalink rules from the old site. Do not invent patterns: WordPress and DLE have custom routes and plugins that change URLs.

## Example: explicit legacy URL map (recommended)

Write a private JSON object keyed by `source_type:source_id` using **exact identifiers from the content execution receipt**. Each value can be a single URL or an array of URLs. Absolute URLs must belong to `--origin`.

```json
{
  "post:42": [
    "/news/42-old-post.html",
    "https://old.example.com/news/archive/old-post.html"
  ],
  "page:7": "/about-us.html"
}
```

For WordPress plain query permalinks, include `/?p=42` (posts) or `/?page_id=7` (pages). Only these two exact single-parameter URL forms are supported, preventing redirects from arbitrary query strings.

Run as a trusted operator (not from a browser request):

```bash
php bin/cajeer migration:seo-import PROJECT_ID --yes \
  --origin=https://old.example.com \
  --legacy-map=/srv/migration/verified-urls.json
```

A verified permalink template is an alternative when all relevant URLs follow one structure:

```bash
php bin/cajeer migration:seo-import PROJECT_ID --yes \
  --origin=https://old.example.com \
  --wp-pattern='/%year%/%monthnum%/%postname%/'

php bin/cajeer migration:seo-import PROJECT_ID --yes \
  --origin=https://old.example.com \
  --dle-pattern='/news/%id%-%slug%.html'
```

Supported template tokens: `%id%`, `%post_id%`, `%slug%`, `%postname%`, `%year%`, `%monthnum%`, `%day%`. WordPress date tokens require a valid source UTC date. Explicit JSON mapping takes priority for any included source record. Omitting both mapping and templates still imports SEO metadata, but **cannot** create reliable legacy redirects or rewrite links.

No wildcard URLs, unrestricted redirect targets, credential-bearing URLs, external hosts, percent-encoded separators, reserved application routes or arbitrary query string redirects are accepted. Maps are limited to 1 MiB / 1,000 keys / 12 URLs per record.

## Actual processing

- Source inventory is fetched using the existing encrypted, read-only DLE/WordPress connection or encrypted WordPress WXR. It is never sent to a public endpoint.
- Target IDs come from the completed import receipt. Actual current `RouteIndex` paths are used; unpublished or missing public routes do not receive redirect rules.
- Known DLE `metatitle` / `descr` and WordPress Yoast / Rank Math / AIOSEO title and description keys are imported when present. Unsupported plugin template expressions are **not** evaluated. A recognized noindex flag is retained. Existing explicit SEO fields entered by editors always take precedence; the migration does not overwrite those fields.
- Canonical URLs default to the **new public URL**. Historic old-site canonical URLs are not copied to the new site. Source-side robots rules beyond recognized noindex keys are not invented.
- New 301 rules use the existing `RedirectRepository`, with route conflicts and cycles rejected. Live pages always win. WordPress plain `/?p=ID` and `/?page_id=ID` are stored in a separate indexed SQL table and only matched at the site root on exact single-parameter requests. Unrelated UTM and query URLs remain untouched.
- Internal links are rewritten in nested content fields only for **verified old paths** with a published destination. Unrelated remote hosts and unknown URLs remain unchanged. An entry edited after the content/media stage is rejected before any writes.
- SEO overlay, legacy query rules, new ordinary redirect rows, content link updates, refreshed public route/search projections and the execution receipt commit together in one SQL transaction (the pre-existing CMS write lock is used).
- On success, re-running with identical settings returns the existing receipt. Changing the mapping, source state or options after completion is rejected; create a new migration project for a new transfer.

## Verification

Check old path URLs return 301 to public targets. Check WordPress `/?p=ID` and `/?page_id=ID` when explicitly mapped. Open `/sitemap.xml` and verify published non-noindex URLs are present and flagged noindex URLs are omitted. Verify source SEO titles/descriptions in public page output; editor-authored metadata still takes precedence. In Migration Center, the completed project displays metadata count, new 301/query redirects and number of links rewritten. For sites with many pages, inspect `sitemap.xml?page=N` as appropriate.

Test commands:

```bash
php tools/seo-url-migration-regression.php
python3 tools/seo-url-migration-sqlite-regression.py
php tools/seo-url-migration-integration.php --require-db
php tools/ci-check.php --json
node admin/scripts/publish-admin-assets.mjs --check
```

Full PHP SQL integration requires `pdo_sqlite`, `dom` and `mbstring`; live DLE/WordPress credentials are required to test an actual connected customer site. The SQL schema test via Python does not substitute for PHP E2E. Source releases may be built without Composer vendor; do not treat local `vendor` warnings as full dist certification.

## Supported scope and limits

- Validated migrated records only; no crawl of arbitrary URLs, plugin shortcode execution, blind URL discovery or whole-site redirect guessing.
- Dynamic sitemap and robots integration uses the existing CMS; submitting the sitemap to search engines is an independent webmaster operation.
- Old attachments are handled by 1.4.6. This step does not fetch remote uploads.
- Supported noindex and SEO plugin keys depend on what the source connector can actually read. Some REST APIs hide metadata; unknown serialized plugin settings remain untouched.
- Maximum source inventory remains the bounded migration limit of 500 records per project; large sites and background checkpoint orchestration are separate 1.4.8 work.
- Verification is required before DNS switch-over. Run a full backup, sample old links and check analytics / webmaster data after cutover.
