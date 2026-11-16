# WordPress Migration — 1.4.4

The Migration Center now executes real WordPress content imports, not just discovery/planning. Source projects are WXR (1.0–1.2), read-only MySQL/MariaDB, or HTTPS REST (visible posts/pages). DB and WXR support custom post types through explicit target mapping. Existing DLE projects and their execution receipts remain supported.

## Workflow

1. Back up database, files, and encryption keys; stop workers/scheduler. Use a staging installation for the first transfer. Configure `CONTENT_STORAGE=database` and `CMS_STORAGE=database` and run `php bin/cajeer migrate` (0019 creates the WordPress receipt table).
2. Open `/admin/#/migration`, create a WordPress project, upload WXR or configure the allowed read-only database/HTTPS source. See [discovery](migration-center.md). Source DB credentials must have SELECT-only grants. WordPress REST Application Password is optional for public data.
3. Run Discovery, map source types to existing content types, map title/body/excerpt/slug/custom metadata, categories/tags, and existing author UUIDs, then Dry Run. Map `publish` to `published` explicitly if desired. Private, future, protected and other non-public records cannot automatically become public. New users are deferred; select `skip` or link to existing accounts.
4. Resolve conflicts and approve. Re-run Dry Run for any previously approved WordPress plan created before 1.4.4 (source normalization and hashes changed). Choose `fail`, `skip`, `update` or `create` for slug collisions.
5. Execute the command displayed by the wizard as an operator:

```sh
php bin/cajeer migration:wordpress-import PROJECT_ID PLAN_SHA256 --yes
```

The CLI acquires the exclusive application lease, checks recovery/deployment state, reads the exact source again and revalidates the approved plan against the destination under the SQL writer lock. Content, route and search SQL projections and the execution receipt commit together. On a failed batch, correct the source/mapping/target and re-plan; a committed project is immutable and a repeated command with the same hash returns the saved receipt. Refresh the wizard for the actual source ID → destination UUID mapping and download the JSON report. Different projects are not globally deduplicated; use conflict policy and inspect the target before creating a new project.

## Content and security

- `post`, `page` and custom content types from DB/WXR map to existing content types (including a pages type); page-parent information can be mapped via `meta:wp_parent` but does not create hierarchical routes. REST reads only posts/pages available through the standard collection routes.
- Categories/tags are mapped as fields, not created as a separate taxonomy tree. DB/WXR supply slugs, REST supplies IDs. Additional `postmeta` values are strings (repeated keys use the first value); JSON transforms can parse JSON, not PHP-serialized objects. REST meta depends on registration and permissions.
- `meta:wp_date_gmt` is normalized from the source UTC date; creation/publication timestamps are restored when valid. Local-only timestamps are not interpreted as UTC. On update, the existing creation timestamp is preserved. `meta:wp_protected` is a boolean; source passwords are not imported. Password-protected records are treated as draft, not public. Future UTC dates are likewise non-public.
- Richtext passes the existing SafeHtml allowlist during planning and execution. Static Gutenberg HTML survives where supported; HTML comments, scripts, iframes, dangerous attributes and unsupported markup are stripped. Shortcodes remain inert text; no PHP, plugins or themes are executed. Links and images are not downloaded or rewritten in this release. Do not expose migrated private content until its visibility is reviewed.
- Existing authors are linked by explicit UUID. New users, password migration, comments, menus, attachment bytes, featured image references, independent taxonomy hierarchies, page parent routes, plugin-specific functionality and SEO redirects are deferred to later releases. Unsupported items must be migrated separately; WXR is not a full site backup.

Limits: 500 content records, 500 users, 16 MiB inventory; WXR 8 MiB. PHP DOM is required for execution, XMLReader for WXR, PDO target driver and pdo_mysql for DB source, cURL for real REST, sodium for source credentials. No new write endpoint: execution is operator-only. Existing DLE receipt table is unchanged.

## Tests

`php tools/wordpress-migration-regression.php --require-db --require-xml --require-mysql` (requires MariaDB test service, `MIGRATION_TEST_MYSQL=1`, `MIGRATION_TEST_MYSQL_PASSWORD`), `python3 tools/wordpress-migration-sqlite-regression.py` and the migration wizard browser regression. Production release CI runs these checks; actual source installations and plugin-specific content still require acceptance testing.
