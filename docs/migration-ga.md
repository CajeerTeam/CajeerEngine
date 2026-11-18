# Migration Center DLE + WordPress — 1.5.0 GA

The 1.5.0 release strengthens the **existing real DLE / WordPress import, media, SEO, backup, incremental sync and cutover pipeline**. It does not execute WordPress/DLE plugins or arbitrary source PHP, perform DNS changes, or claim to import arbitrary proprietary extensions.

## Upgrade from 1.4.9

1. Stop active migrations, workers and schedulers. Back up the database, `.env`, `storage/`, media and the operator's encryption keys.
2. Deploy the changed files and run `php bin/cajeer migrate`. **Migration 0025 is required** on SQLite, PostgreSQL and MySQL/MariaDB. It creates `ce_migration_seo_path_evidence` and does not rewrite existing content or migration receipts.
3. Restart workers. On a **staging copy**, check migration projects, old URL rules, file availability and live browser redirects. Re-run `migration:validate` and investigate every reported blocker before cutover.
4. Run existing migrations and sync/cutover commands as documented in `docs/migration-validation-cutover.md`. No new writable HTTP endpoint or bypass of the exclusive operator lock is introduced.

## End-to-end production operation

```bash
# Inspect the source and mapping in /admin/#/migration, approve Dry Run.
php bin/cajeer migration:execute PROJECT_ID PLAN_SHA256 --yes --batch=50
# Preserve the original uploads and run migration:media-import when needed.
php bin/cajeer migration:media-import PROJECT_ID /srv/source/uploads --yes --origin=https://old.example.org
# Map verified old URLs, run migration:seo-import as needed.
php bin/cajeer migration:seo-import PROJECT_ID --yes --origin=https://old.example.org --legacy-map=/srv/source/urls.json
# Check content, identities, media, SEO and URLs against real source and target.
php bin/cajeer migration:validate PROJECT_ID
# If new/changed content is reported, inspect the report then:
php bin/cajeer migration:sync PROJECT_ID VALIDATION_SHA --yes --batch=50
# Revalidate, take a maintenance window, and cut over when ready.
php bin/cajeer migration:validate PROJECT_ID
php bin/cajeer migration:cutover PROJECT_ID VALIDATION_SHA --yes --target=https://new.example.org
```

Run each command with the actual IDs and validation digest obtained from your own installation. Do not copy sample IDs. Operator commands keep destructive database restore separate (`migration:rollback` / `migration:sync-rollback`), and restore leaves the instance in maintenance pending verification.

## What 1.5.0 verifies

- Approved plan and persisted content receipt agree on source keys, actions, destination types and target UUIDs. Missing, duplicate or unapproved receipts are blockers.
- Every imported target entry matches the latest stored content-revision snapshot, including **title, slug, status, locale and data**; silent changes that keep a slug/status constant are no longer ignored.
- Every revision after the committed import/sync receipt must have verifiable migration provenance. Versions 1.5.0+ mark content-import, media-rewrite and SEO-rewrite revisions separately from ordinary editor updates. A verified 1.4.x media rewrite with the exact previous revision and SHA-256 can be accepted, but an unlabelled SEO/editor revision **cannot be silently certified**.
- A post-media/SEO incremental update is allowed to use the most recent **verified** target revision as its optimistic-concurrency precondition. Unverified edits still cause a hard conflict.
- Pending media rewrite receipts block cutover. Media files recorded in the import ledger must exist and match SHA-256.
- An SEO stage receipt is not sufficient on its own: each imported content record requires matching SEO metadata. For 1.5.0+ SEO migrations, project-owned 301 redirect paths and destinations are recorded in SQL and verified during validation. Existing query-URL rules are checked through the actual route resolver.
- Published pages require real public routes and sitemap entries, except for noindex pages. Source deletions are reported and never implicitly delete target content.
- The cutover command requires a fresh matching validation digest, matching HTTPS `APP_URL`, a verified backup, a valid route graph and the exclusive operator lock. It does not change DNS or proxy configuration.

## Existing 1.4.9 projects with historical editor/SEO revisions

Pre-1.5.0 SEO rewrites were stored with the same revision reason as editorial updates. They **cannot be distinguished reliably** from manual edits using old SQL receipts. On upgraded projects, `migration:validate` may report `unverified_editorial_revision` even when an old SEO rewrite was legitimate. This is a deliberate fail-closed decision, not data loss. Review the target content and revision history on staging; resolve the discrepancy using a controlled new import and/or an operator-reviewed migration project, instead of ignoring the error or editing SQL receipts manually. Historical 301 rules created before 1.5.0 lack per-path evidence; inspect them against a preserved old-URL inventory during cutover rehearsals.

## Limitations and acceptance

- The 1.4.8 source inventory limit remains **5,000 records, 5,000 users, 64 MiB inventory and 32 MiB WXR**. Destination writes use 1–100-record SQL checkpointed batches; source inventory is **not** unlimited streaming.
- Media transfer currently imports from a local copy of `uploads`. A WXR file alone does not contain file bytes.
- Unknown plugins, theme PHP/shortcodes, comments and some custom taxonomies are not guaranteed equivalent. Document and manually handle any unsupported dependencies.
- Source and destination are not atomically snapshotted together. Freeze old-site writes for the final validation / incremental sync / cutover window.
- The application does not verify external DNS, CDN invalidations, reverse-proxy wiring or third-party credentials on its own; the operator must validate them during a staging rehearsal and maintenance window.
- `php tools/ci-check.php` verifies source completeness, not successful live-source migration. Release CI runs additional DB/DOM/MySQL/Chromium regressions; the live-site acceptance is still a separate operational requirement.

For technical evidence, run `php tools/migration-ga-regression.php` and `python3 tools/migration-validation-sqlite-regression.py` and inspect the release CI result for your exact commit and artifact. Do not consider a local source-only PASS a full production certificate.
