# 1.7.4 — AI SEO & Content Insights

## Functionality

This is **executable integration** between synchronized Analytics Hub page snapshots and the published CMS route graph; it does not crawl the live public site or read provider credentials. Open **AI SEO Assistant → AI SEO & Content Insights** in either Nuxt or the production static admin SPA. Select exactly one Analytics Hub connection; optionally specify a synchronized period. The service maps each provider page URL to the canonical origin configured by `APP_URL` and a published CMS route. Results include the existing actual SEO audit, numeric per-page observations, quality warnings and prioritized suggested work.

The server supports `GET /api/v1/ai/seo/insights?connection_id=...&limit=40[&from=YYYY-MM-DD&to=YYYY-MM-DD]` (requires both `analytics:read` and `content:read`). Up to 100 provider URL rows are examined and at most 40 recommendations returned. No cross-property aggregation, fuzzy URL matching, inferred cross-domain mapping or made-up traffic. Sources without URL breakdown display an explicit message. Pages with `noindex`, unpublished routes, invalid URLs or a different host are excluded.

Period comparison is only shown when Analytics Hub verifies matching previous period and provider/metric schema, and page-level sections do not report incomplete, sampled, truncated or otherwise limited data. Insufficient baseline volume suppresses percentages; remaining SEO audits are still available. URL-query parameters and fragments are never included in a matched CMS path. A change in a measured metric **does not establish the cause** of a search performance change. The priority is an editorial heuristic, not a search ranking factor.

## Editorial workflow

An editor who additionally has `content:write` and a valid administrative browser session can click **Prepare SEO Title / Description**. `POST /api/v1/ai/seo/insights/proposals` re-reads the provider snapshot and the CMS record server-side, verifies the chosen field is genuinely recommended, enforces a per-user limit, and calls the existing production AI Content Editor with numeric evidence and the actual page contents. The result is a **proposal only**; it does not modify content. The UI displays `original`, `proposed` and `expected_revision`. Applying the proposal requires an explicit confirmation of the published page and uses AI Editor's established revision conflict protection and audit. A concurrent edit cannot silently be overwritten. All AI requests consume the configured shared token budget; obtaining deterministic insights does not call OpenAI.

## Operations and acceptance

1. Back up the existing deployment; apply the changed-only ZIP over **the entire installed 1.7.3 tree**, not over an older partial patch.
2. Set the correct public `APP_URL`, configure one Analytics Hub provider and synchronize two **equal-length completed periods** (current and immediately preceding). Do not pass credentials or raw URLs into manually written AI instructions.
3. Run `php bin/cajeer api:lint --strict`, `php bin/cajeer admin:assets --check`, `php tools/ai-seo-content-insights-regression.php` and `php tools/ai-seo-content-insights-integration.php --require-db` on a release environment with PDO SQLite and the required extensions.
4. Check the displayed source, matched route, SEO issues and numerical comparisons against Analytics Hub. Prepare and approve a single SEO Title change; check revision history, public metadata and audit log. Test unauthorized access, stale revisions, sampled/truncated data, API failure and CMS access without analytics scope.
5. Production readiness additionally requires staging with a real OpenAI API account, source-provider synchronization and the actual configured database driver. Passing PHP syntax or browser mocks alone is insufficient.

No new migrations are required. This version does not automatically publish anything and does not use Search Console live API to verify indexing.
