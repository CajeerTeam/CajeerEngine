# Migration Validation & Cutover (1.4.9)

This is an operator-only continuation of the existing DLE / WordPress Migration Center. It does not import arbitrary code, change source CMS databases, or modify DNS.

## Requirements

1. Apply `0024_migration_validation_cutover.sql` using `php bin/cajeer migrate`.
2. Keep `CONTENT_STORAGE=database` and `CMS_STORAGE=database` and the original migration project/credentials.
3. Complete the existing content import and, where applicable, Media Migration and SEO Migration. Keep a recoverable backup of `.env`, storage, and the database. Check the configured `APP_URL` before activation.
4. Use the same operator key/permissions used for `migration:execute` and backup capture. Execution requires the exclusive operation lock.
5. PHP DOM, the target PDO driver, and the source DB/XML/HTTP extensions needed by the migration source are required.

## 1. Revalidate source and target

```bash
php bin/cajeer migration:validate PROJECT_ID
```

The command reads the source again. It compares the imported source-ID ↔ target-UUID receipt, the original approved normalization fingerprint, later sync receipts, published routes, expected sitemap entries, the SEO stage and SHA-256 of files in the project's media journal. It also detects source deletions without deleting targets, new/changed source records, malformed data, target conflicts and damaged media. It stores an integrity-bound report in `ce_migration_validation` and exposes its **summary** in `/admin/#/migration`.

A changed source record is not silently overwritten; a stale target revision, changed slug, missing author mapping, or duplicate target slug is a blocker. Entries intentionally excluded by mapping remain excluded. The report includes `sha256`, `changes`, `problems`, `drift`, counts, readiness and the snapshot time. Metadata updates made by editors or earlier media/SEO operations can deliberately block incremental updates until operator reconciliation. This prioritizes preventing accidental loss of editorial changes.

The system cannot prove every theme-specific widget, plugin, comment, or external attachment is equivalent. Counts are not proof of semantic equivalence; review representative pages and non-core plugin integrations manually.

## 2. Incremental synchronization

When the report contains `changes` and no blocking problems, review the **exact** proposed operations and then run:

```bash
php bin/cajeer migration:sync PROJECT_ID VALIDATION_SHA --yes --batch=50
# Optional one-batch execution for rehearsals
php bin/cajeer migration:sync PROJECT_ID VALIDATION_SHA --yes --batch=50 --max-batches=1
# Repeat the same command to resume from its committed checkpoint
```

The first run verifies that source and target still match the reviewed SHA, captures and verifies a full pre-sync backup, places the site into maintenance, and creates a durable SQL run. Each batch applies source changes through `ContentEntryRepository::importMigrationEntry()` under `RouteIndex::mutate()`. Its target changes, source-ID map, and cursor commit **atomically**. On re-run, only uncommitted batches execute. New users/identities, media, or SEO for **new incremental entries** are not automatically imported: those require a separate supported migration stage and new validation before cutover. A missing/new author mapping blocks automatic sync. The implementation retains the 5,000 record and 64 MiB source-inventory limits introduced in 1.4.8.

The application stays under maintenance after incremental sync until validation and cutover succeed or the operator restores the site. If the source changes mid-operation, the next invocation refuses to resume using the old snapshot. Do not unpause by deleting state files manually.

### Restore the entire pre-sync snapshot

```bash
php bin/cajeer migration:sync-rollback PROJECT_ID BACKUP_ID --yes
```

Restore is **full-instance**, not limited to changed rows. It replaces database/storage with the verified pre-sync snapshot and intentionally leaves maintenance enabled for inspection. This command also covers a crash after backup and maintenance were written but before the SQL run was persisted, provided the project and backup ID match the maintenance marker. Recreating the site using unverified files is not supported.

## 3. Cutover

After sync, rerun `migration:validate`. Resolve missing content, missing public routes/sitemap entries, missing SEO stage, damaged media, and remaining source deltas before proceeding. A report is valid only for the exact source and target state it describes.

```bash
php bin/cajeer migration:cutover PROJECT_ID VALIDATION_SHA --yes --target=https://site.example.com
```

The target must exactly match the configured public `APP_URL` (production HTTPS). The command re-scans the source and target, checks the route graph and generated sitemap, verifies or creates a full cutover backup, saves a durable activation receipt, and clears **only its own** synchronization maintenance marker. It never disables unrelated maintenance, runs remote scripts, or edits DNS/load balancer/CDN settings. If traffic is already pointed at CajeerEngine, clearing the owned marker exposes the site's published routes. Otherwise, point the hostname/reverse proxy at the installation using your infrastructure change process and verify TLS, 301s, cache behavior, forms, access control and monitoring.

A second invocation with the same validation hash and target is idempotent; incompatible repeat activation is rejected. A post-cutover revalidation can detect new drift, but the application does not continuously monitor old-source changes.

## Operational safety and limits

- Always rehearse on a staging copy and verify full backups before migration or cutover.
- Source read/write ordering cannot provide an atomic snapshot of a **live external CMS** without cooperating database snapshot/maintenance. Freeze authoring or run a final validation during the maintenance window.
- Existing queued emails, marketing callbacks, caches, and third-party plugins should be checked separately; they are not automatically migrated.
- SEO mapping and sitemap validation only cover routes that CajeerEngine knows about. Manual permalink migrations must be executed with the existing `migration:seo-import` command.
- Cutover is a **local application activation and audited handoff**. DNS changes and verification of an actual domain require infrastructure access and are not performed by this release.
- If a runtime error occurs after a backup and maintenance marker are installed, inspect `migration:validate`, the SQL run state, and the marker; use the matching backup ID rather than attempting unsafe manual recovery.
