# CajeerEngine 1.4.8 — Migration Execution & Recovery

This release adds a CLI-only, **real checkpointed content importer** for approved DLE or WordPress projects. It reuses validated mappings, SQL content repositories, role safety checks, RouteIndex and the existing complete backup/restore system. This is not a general streaming unlimited-site importer; the bounded current inventory is **5,000 records, 5,000 users, and 64 MiB of normalized JSON** (WXR input maximum 32 MiB). Larger sites must be split into approved projects. For DB sources, discover and plan under read-only credentials, on a stable source snapshot.

## Prerequisites

- Back up and stop other jobs, editors, schedulers and web workers that might mutate site data. Use `CONTENT_STORAGE=database` and `CMS_STORAGE=database` and apply migration `0023_migration_execution.sql` using `php bin/cajeer migrate`.
- Configure and **test** the production BackupManager, secret key, storage permissions and DB dump/restore utilities (`pg_dump/pg_restore` or supported MySQL/SQLite equivalents) before execution.
- Run fresh Discovery, mapping, Dry Run and approve the SHA-256. The source must remain unchanged until completion. Each retry reads the complete bounded inventory and checks its SHA-256.
- Run from an operator shell with secure environment credentials. Do not expose execution or rollback over HTTP.

## Operator commands

```bash
php bin/cajeer migration:execute PROJECT_ID PLAN_SHA256 --yes --batch=50
php bin/cajeer migration:status PROJECT_ID
# Ask the currently running importer to stop at the next committed batch:
php bin/cajeer migration:pause PROJECT_ID
# Resume after interruption, or after a max-batches limited run:
php bin/cajeer migration:resume PROJECT_ID PLAN_SHA256 --yes --batch=50
# Test exactly one batch then pause (without claiming completion):
php bin/cajeer migration:execute PROJECT_ID PLAN_SHA256 --yes --batch=50 --max-batches=1
```

Batch size: 1–100 records, default 50. `--max-batches` is optional; zero means no limit. On start, the importer rechecks the approved plan, verifies a **full pre-import backup**, writes a site maintenance marker, then creates its SQL checkpoint. A batch's content changes, route/search projections, source/target identity mappings, journal entries and cursor update commit atomically. The final legacy receipt is created **only after the final batch**, so existing Media/SEO stages can still consume it. If a process exits, already committed batches remain and an operator can resume. A failure does not automatically reopen the site.

The maintenance marker is kept during pause/crash and removed only on successful completion. To view progress while maintenance is active, use the CLI `migration:status` (do not disable maintenance manually). A `migration:pause` command uses a small local request file and does not need to acquire the global exclusive lease. On resume, the project, SHA, source inventory and backup identity are checked again. User creation is executed only with the first successful batch.

## Full rollback / recovery

```bash
php bin/cajeer migration:status PROJECT_ID
php bin/cajeer migration:rollback PROJECT_ID VERIFIED_BACKUP_ID --yes
# Verify DB, files, routing, users and analytics with maintenance ON.
php bin/cajeer maintenance:off
```

**Warning:** rollback restores the **whole application database and files**, not just the imported entries. Any other changes after snapshot creation will be lost. Ensure all writers remain quiesced until completion/rollback. The backup ID is printed in the execution status. The CLI authenticates the original backup and refuses mismatched project/backup markers, active updates and deployments. If backup restore fails, maintenance remains enabled and operator intervention is required. After a successful rollback maintenance also intentionally stays enabled until an operator verifies the site and explicitly disables it.

For a crash between creating the maintenance marker and creating the SQL checkpoint, the same restore command can be used with the backup ID from `storage/app/maintenance.json`. A partially completed restore also requires the original backup ID. Never delete the marker to "fix" an interrupted migration.

## Scope and limits

- Actual DLE/WordPress writes, mapped users, route/search updates, journal and restart behavior are implemented; Media Migration (1.4.6) and SEO Migration (1.4.7) remain separate post-content steps **after successful completion**.
- This release does **not** offer automatic rollback of just a failed batch: failed batches roll back their SQL transaction, while full recovery explicitly restores the verified pre-import snapshot. There is no cluster-wide distributed lock or automatic zero-downtime cutover.
- Planning uses bounded in-memory inventories (not unlimited streaming source reads). The size and record limits are enforced before approval. WordPress REST inventory is constrained by API visibility, pagination and timeouts.
- Test a complete migration and restore on a non-production copy with the same database engine; SQLite schema checks alone cannot certify PostgreSQL/MySQL or PHP repository execution.
