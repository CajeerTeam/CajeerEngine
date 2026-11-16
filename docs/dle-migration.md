# DLE Migration — 1.4.3

DLE DB projects support actual transfer of news (`post`) and static pages (`static`) to existing CajeerEngine content types. WordPress execution remains a later release. Use a separate SELECT-only DLE user and the existing `MIGRATION_DB_ALLOWED_ENDPOINTS` policy. Source credentials remain encrypted. Target requires database-backed content and CMS storage, the PHP DOM extension, and the relevant PDO drivers. The import is a bounded single-node operation, not a background job.

## Run

1. Back up the target database and `storage/` with the existing backup tools. Apply `php bin/cajeer migrate` (0018).
2. Open `/admin/#/migration`, run Discovery, configure mapping and explicit author choices. Rebuild and approve the plan after upgrading from 1.4.2: DLE category inventory, safe HTML and publication checks changed.
3. Set `CONTENT_STORAGE=database` and `CMS_STORAGE=database` for the target; do not mix file and SQL content. Stop concurrent writers or use the exclusive operator lease. Copy the exact command shown in the wizard:

```sh
php bin/cajeer migration:dle-import PROJECT_ID PLAN_SHA256 --yes
```

The command acquires an exclusive application lease; busy servers return a retryable error instead of writing while another operation is active. It re-reads source data and compares the fresh plan under the target route-index transaction. All content writes, SQL routing/search projection updates and the receipt commit together. A route collision, validation error, SQL failure or changed plan aborts the entire batch. An interrupted transaction is rolled back by the database; repeating a completed command returns the stored receipt without re-reading the source or modifying target content. The wizard shows the completed result on refresh. Completed projects are immutable. This guarantee is per project; new projects still require explicit conflict rules and do not automatically match previous projects' source identities.

## Content mapping

* `post` / `page` → existing target content types. `title`, `body`, `excerpt`, `slug`, `date`, `categories` (original IDs), `tags`, `meta:<xfield>` are supported.
* `meta:category_names` supplies readable names; `meta:category_tree` supplies arrays of ancestor paths (map to a JSON field with `copy` or `json`). Missing IDs are reported; cycles abort planning. This does not create a standalone taxonomy module.
* `meta:dle_views`, `meta:dle_rating`, `meta:dle_seo_title`, `meta:dle_seo_description`, `meta:dle_seo_keywords` are available when present. Map to appropriate target fields.
* Richtext is sanitized in both planning and execution using the same function. Simple `[b]`, `[i]`, `[u]`, `[s]`, `[quote]`, `[code]` are converted; unsafe HTML and attributes are stripped. Arbitrary template PHP, plugins and shortcodes never execute. External image URLs are not fetched; media rewrite is deferred.
* News with `approve=1` and no detected restrictions can map to `published`. Static pages remain draft unless source schema explicitly indicates a public group or approval. Restricted content maps to draft; verify source category/plugin ACLs separately before publication. Dates are interpreted as UTC; valid source dates become creation dates for new entries and publication dates for published entries. Invalid dates retain normal target timestamps with warnings. Existing update creation date is preserved.
* Existing active authors may be linked by UUID; new account creation is deferred (planned `create` authors are not created). On update an unlinked author preserves the existing target author. No passwords or account permissions are copied.
* Mapping strategies: `fail`, `skip`, `update`, `create` (new slug). Unresolved conflicts block execution. Each result records source type/id, target UUID, action, slug and revision; skipped rows are recorded without writing.

Limits inherit planning: 500 content records, 500 users, 500 categories, 16 MiB of inventory, 10,000 target rows per mapped type. A large source fails closed instead of silently importing a prefix. Standalone taxonomy, media, comments, SEO redirects, incremental sync, full site rollback and cross-project deduplication are not part of 1.4.3. Use the existing operator backup for restoration after a successful import. No automatic backup/restore is attempted by this command.
