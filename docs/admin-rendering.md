# Admin & Public Rendering 1.1.9

## Upgrade from 1.1.8

Back up the installation and apply the signed distribution with the existing updater. For a source patch, deploy the changed files together, run core migrations and `php bin/cajeer cms:bootstrap` (without `--demo-home`). This extends the built-in `pages` type with missing standard blocks; it does not replace an existing custom definition. Existing page records and URLs are not rewritten. For the Static SPA run `php bin/cajeer admin:assets --check`.

## Editorial workflow

1. Open `/admin/#/content-types` to create a schema using the field builder. The advanced JSON editor allows editing block definitions and field settings. Existing definitions are preserved when editing a type.
2. Open Content, choose a type and create a draft. The entry editor is generated from the current schema. It supports text, textarea, rich text (formatting controls with HTML source), numbers, dates, datetimes with timezone, booleans, relation IDs, media IDs, JSON, and blocks. Use the media search/select control for public assets, including inside blocks.
3. Add, reorder or remove blocks; enter SEO and page parent/path. The standard page type has text, hero, CTA, image, gallery and columns. Use the advanced JSON mode for custom fields. Switching back from JSON rebuilds the structured editor on the next render.
4. Save the draft, then use Preview token and the generated link. Preview is never cached and retains noindex/no-referrer. The toolbar links back to the editor. Save as published or use the Publish action to make the indexed route visible.
5. Open an existing entry to edit it. The editor sends `expected_revision` on PUT; a 409 `revision_conflict` means another change occurred. Reopen the entry and reconcile edits before resaving. Older clients may omit the optional precondition for backward compatibility. The browser retains unsaved form edits in memory and sessionStorage (per account/type/entry), with an explicit restore button. Logout clears these local drafts. This is local autosave, not a separate server-side draft of a published record.
6. Revisions opens historical versions and Restore returns the chosen snapshot as a draft. An existing published URL is removed until republished; the UI confirms this explicitly. Translations lists related entries and creates a draft for a new locale; the locale may need a different path or parent before publication.

The CMS screen includes a theme picker, site identity, accent, indexing and routing controls, a flat navigation editor with reorder/delete, and redirect editing. Complex menu nesting is not supported by the current repository. Existing signed modules and operational screens remain available.

## Themes and rendering

`active_theme` supports `auto` (the existing enabled signed theme, otherwise standard), `default`, `editorial`, or the name `vendor/theme` of an installed enabled theme. Select a theme from the CMS screen. `editorial` is a bundled alternate `.cjr` page template using the standard layout. Signed extension themes use the established `templates/page.cjr` and their own includes/layouts, with `theme_assets` for published assets. No new PHP execution surface or untrusted theme installer is added.

`blocks_html` contains the rendered standard blocks; `content_html` is sanitized. Custom themes can use these values or render their own components from `data.blocks` (`handle` and `data`). The renderer resolves media UUIDs in current content by ID instead of exposing the entire media catalog. `media_by_id` and `media` contain only referenced public records. Private media are not rendered in standard blocks. Existing themes that depended on receiving the full public library must update to explicit content references. Rich HTML sanitization uses DOM when available; without DOM it is escaped, not interpreted. Only trusted signed theme code should be installed.

## Optional render cache

Set `PUBLIC_RENDER_CACHE_TTL=60` in `.env` to enable a bounded time-to-live per public path (0 disables, max 3600). The key includes the current render context, referenced media metadata, navigation, theme config, and content hashes of templates and renderer code. Publication is checked before cache lookup, so unpublish and preview cannot expose cached HTML. Changes to these dependencies are observed without waiting for expiry. The cache is local, not a shared distributed cache; it avoids repeated template rendering, not route/DB reads or block preparation. Signed extension themes and the alternate editorial theme are rendered directly. Remove `storage/cache/public` to reclaim obsolete cache files; updates clear the cache. Compiled templates use source hashes and a recursion bound.

## Static site export

`php bin/cajeer static:export --json` writes `storage/static-export/site/` using real public routes, including published noindex pages and the actual homepage. It copies public media and current variants and enabled theme assets; private media, admin, and drafts are excluded. Sitemap and robots are included. The generated site requires a published homepage and `STORAGE_PUBLIC_UPLOADS_URL=/uploads`; serve the export root with directory indexes enabled. Build in a staging directory and replace the previous export only when successful. The CLI holds the existing exclusive activity lock. This is a static HTML snapshot: API functionality, preview, search, and dynamic redirects are not exported as a web server runtime. Configure HTTP 301/410 and error-page status handling separately on the static host. Keep `APP_URL` set to the intended public origin.

## Verification

```
php tools/rendering-regression.php
php tools/rendering-regression.php --database
php bin/cajeer api:lint --strict --json
php tools/generate-api-sdk.php --check
php bin/cajeer admin:assets --check
```

Optional Chromium UI regression: install Python Playwright and Chromium, then `python tools/admin-editor-browser.py`. This exercises actual static JS with controlled API responses (not a database-backed HTTP E2E test). The PHP regression exercises real repositories and rendering. PDO SQLite mode is included in release CI. The fully installed production environment still requires the normal Composer lock, dependencies, PHP extensions and deployment acceptance tests.
