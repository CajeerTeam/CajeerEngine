# CajeerEngine 1.6.3 — AI SEO Assistant

Production source integration for the existing OpenAI Responses API, editorial proposal store, content validator, public route index, CMS revisions and both administrator interfaces.

## Upgrade from 1.6.2

1. Back up the database and `storage/`, and keep a verified copy of 1.6.2 for rollback.
2. Apply the **changed-only** ZIP on top of a complete installed 1.6.2 application, preserving paths.
3. Deploy the updated PHP classes, routes, OpenAPI and SDKs; restart PHP workers and invalidate PHP opcache.
4. Publish the updated `public/admin/` contents and verify `node admin/scripts/publish-admin-assets.mjs --check` when a Node runtime is available.
5. Run `php bin/cajeer api:lint --strict` and your standard health/release checks.
6. Open **Admin → AI SEO Assistant**. Choose a content type and a saved entry, run an audit, then request a recommendation if an OpenAI API key is configured. Confirm the proposal to persist a new CMS revision.

No SQL migration is required for 1.6.3. SEO proposals reuse `ce_ai_editor_proposals` and `ce_ai_editor_jobs` (migration 0032), while usage accounting uses migration 0031. If upgrading from earlier than 1.6.2, run `php bin/cajeer migrate` before using AI.

## Implemented functionality

- GET `/api/v1/ai/seo/audit?type=pages&entry_id=<uuid>` analyzes a saved record, its public CMS route and its SEO metadata without fetching remote URLs.
- GET `/api/v1/ai/seo/batch?type=pages&limit=30` audits up to 50 of the newest published entries in the selected content type. Returns the total count and inspected reports.
- POST `/api/v1/ai/seo/proposals` with `{ "type": "pages", "entry_id": "<uuid>", "field": "seo_title", "instructions": "..." }` requests SEO Title or Meta Description generation from the actual saved page content. The field must be `seo_title` or `meta_description`.
- Existing POST `/api/v1/ai/editor/proposals/{id}/apply` approves the proposal, checks ownership, expiry, original field hash and revision number, and saves through the real CMS entry repository. A published page needs `confirm_published=true`.
- SEO Title, Meta Description, canonical URL and noindex are now reserved CMS metadata and survive ordinary content saves even if the content type has not declared those fields. The validator checks type, size and canonical scheme. This fixes a pre-existing mismatch with `PublicPageRenderer` and `RouteIndex`.
- Nuxt `admin/pages/seo.vue` and production static SPA `#/seo` support auditing, batch review, requesting recommendations, viewing inert comparisons, approving or rejecting suggestions and refreshing saved metadata.

## Safety and behavior

- Read operations require `content:read`; generation and application require `content:read` and `content:write`. All endpoints require an authenticated administrative browser session. Mutations use CSRF, rate limits, user-owned proposals, audit and the existing shared token budget.
- External page URLs are never fetched by the SEO audit (no SSRF). The analyzer uses the saved fields and the CMS route map. Broken-link checks cover ordinary root-relative HTML links whose paths are not found in that map; they do not crawl a live site.
- SEO Title and Meta Description are capped at 70 and 160 Unicode characters for AI suggestions, respectively. The CMS validator enforces an upper bound of 180 and 320 characters for manually maintained fields.
- OpenAI receives page text as untrusted **source data**, not as executable instructions. AI output is untrusted and displayed escaped. It is never automatically published. Administrator approval produces a normal revision and can be reverted with the CMS revision-history workflow.
- The audit score is a transparent heuristic, **not** a Google ranking metric. Template-level title suffixes, rendered heading elements, external links and Search Console ranking data are outside the current audit. Canonical/noindex changes are maintained in the existing content editor rather than generated automatically.
- If OpenAI is unavailable or no key is configured, audit and batch review continue to work; suggestions show provider errors without modifying content.

## Acceptance

```bash
php tools/ai-seo-regression.php
php tools/ai-seo-integration.php --require-db
php bin/cajeer api:lint --strict
php tools/generate-api-sdk.php --check
node admin/scripts/publish-admin-assets.mjs --check
CHROMIUM_PATH=/usr/bin/chromium python3 tools/ai-seo-browser.py
```

The PHP/PDO acceptance test requires `pdo_sqlite` and the ordinary Composer runtime dependencies in CI. Browser acceptance uses a mocked API transport to exercise DOM interactions and authorization headers; it does not prove connectivity to OpenAI. Before promoting to production, test with a configured real OpenAI key on staging, including live reauthentication, permissions and rollback.

## Rollback

Stop writes, restore the prior application files, and use the ordinary CMS revision-history operation to undo any 1.6.3 SEO changes. Restoring the full database backup should be reserved for full-system rollback because it overwrites unrelated writes. SEO metadata uses existing entry storage; no migration down-step is necessary.
