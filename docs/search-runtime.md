# Search & CMS Integration — 1.2.0

## Upgrade

Create and verify a backup using the 1.1.4+ backup service before applying the patch. `/search` is reserved: rename any existing page, alias or custom redirect occupying this path before rebuilding. Use a maintenance window for migration and first rebuild.

```sh
php bin/cajeer migrate
php bin/cajeer search:index --json
php bin/cajeer search:check --json
php bin/cajeer routing:check --json
php bin/cajeer api:lint --strict --json
```

The legacy search index is derived data, not a source of content. The new catalog is reconstructed from authoritative CMS repositories. No migration of old search documents is required. `search:index` can also repair a damaged file catalog and removes orphan documents. Do not delete the source content to repair a search index.

## Supported profiles

The CMS uses a single authoritative search catalog according to `CONTENT_STORAGE` and `DB_DRIVER`:

- PostgreSQL (`CONTENT_STORAGE=database`, `CMS_STORAGE=database`, `DB_DRIVER=pgsql`): weighted `tsvector`, Russian/English/simple dictionaries, GIN index and indexed type/locale filters. Recommended deployment profile.
- SQLite/MySQL: normalized title/body substring matching, title weighting, SQL filtering and pagination. SQLite is suitable for small installations; this is not a full-text or high-load benchmark.
- File content storage: versioned JSON catalog with atomic writes and route lock, for small sites and development.

`SEARCH_DRIVER` is a legacy adapter preference; `meilisearch` and `opensearch` adapters remain available as separate classes but do not serve the authoritative CMS search in 1.2.0. Diagnostics explicitly warn if one is configured. There is no external mirror or remote delivery guarantee in this release. This avoids exposing stale public documents through an eventually consistent service. No implicit fallback to file storage occurs on SQL failure.

## Query

```sh
php bin/cajeer search:query "космос" --type=pages --locale=ru --limit=20 --offset=0 --json
curl 'https://example.com/api/v1/public/search?q=space&type=pages&locale=en&limit=20&offset=0'
```

Public UI: `/search?q=space`. The default theme links to it. The admin Search view has the same filters, totals, facets, pagination and a rebuild action. Admin `/api/v1/search` retains `content:read` authorization; reindex requires `system:write`, diagnostics require `system:read`. Public search returns only published and routable content; admin search intentionally uses that same projection, not draft discovery. Query, `type`, `locale`, `limit` (1–100), `offset` (0–100000) and legacy `index=content` are supported. Results include title, excerpt, canonical relative URL, content type, locale, source UUID and relevance. Facets reflect the current filtered matching population; totals are computed before pagination. Search results are not public HTML cache entries.

Only entries reachable through the public route graph are indexed. Unpublished parents, drafts, archived entries, error pages, aliases and entries marked `seo_noindex` are excluded. Search includes title, allowed schema text fields and allowed block text; `settings.private=true` and `settings.searchable=false` exclude fields, and raw HTML is reduced to text. Do not place secrets in public content fields. The indexed body is capped at 50,000 characters. SQL FTS uses language stemming; the file/SQLite/MySQL modes use normalized substring matching, so ranking and linguistic matching are not identical. Empty queries return no results.

## Consistency and recovery

Normal repository changes (including publish/unpublish, delete, translation, revision restore, parent movement, theme/route changes and bulk import) update the projection under the existing route writer lock. SQL commits the source, route index and search projection in the same transaction. The file profile uses snapshots and atomic writes; if a process stops during a change, the source fingerprint triggers a rebuild on the next read. A malformed file catalog fails closed until an explicit `search:index` repairs it. Backup/restore includes the derived data; rebuild after manual restoration if necessary. Changes made directly to SQL tables outside CMS repositories are unsupported; run a rebuild before reopening the site.

Full rebuild replaces the SQL projection inside a transaction and deletes stale documents. The scheduler reconciles initialization/current generation rather than scanning/rebuilding the catalog each minute. The graph is still rebuilt on editorial mutations; large bulk workloads and concurrent writer throughput are targets for the next performance release. The catalog is synchronous, not an outbox or an asynchronous queue. No suggestions service or fuzzy search is included. A static HTML export does not include the dynamic search API; the built-in dynamic search link is removed from exported pages.

## Tests

```sh
php tools/search-regression.php
php tools/search-regression.php --database
# Dedicated empty PostgreSQL database only; configure DB_* first.
CE_SEARCH_PG_TEST=1 php tools/search-regression.php --pgsql
```

The release workflow also exercises public search against actual SQLite and PostgreSQL dist installations, including nested path moves and unpublished descendants. The PostgreSQL and PDO modes require the corresponding PHP extensions and database. Do not infer production certification from the file-mode test or the presence of the CI workflow.

## Performance changes in 1.2.1

SQL edits with unchanged status, locale, slug, parent and explicit paths update only the affected route and search document. Structural edits and changes to schemas or theme settings still reconcile the entire graph. See [database performance](database-performance.md).
