# CajeerEngine 1.8.6 — End-to-End Regression

## What changes in runtime

File-backed CMS preview tokens previously used an unlocked read/modify/write sequence. When two or more PHP workers issued or resolved preview URLs simultaneously, a later JSON rename could overwrite an earlier token or `used_at` update. `PreviewTokenService` now uses a stable sidecar `preview-tokens.lock` across **both** mutations, then publishes the complete JSON state atomically. The sidecar file is not renamed/deleted. New issues prune expired/malformed records, and resolution rejects expired tokens without writing usage state. The SQL-backed implementation additionally filters expired rows before writing `used_at`. No existing token format, URL, API response or database migration changes.

**Operational note:** the PHP user must have read/write permissions on `storage/app/cms`; the new sidecar file must be shared among all PHP-FPM workers that use the **same file storage**. Separate hosts should use the database-backed storage mode rather than distinct local token files. Never remove the sidecar lock file while workers are running; doing so could split concurrent lock domains.

## Tests that actually execute platform code

`php tools/e2e-content-preview-regression.php` runs without Composer, using isolated file storage; `--database` runs against a freshly migrated PDO SQLite database. Both exercise:

1. CMS bootstrap and real draft creation;
2. public routing and search isolation of unpublished content;
3. preview token issuance, resolution, invalid-token rejection and draft rendering;
4. publication and immediate search/route visibility;
5. edit and search index refresh plus stale-revision conflict;
6. actual static export containing published content;
7. unpublish and removal from both search and the next exported site.

The file-mode run also forks 16 independent PHP processes issuing preview tokens against the **same** JSON store and verifies every token survives, can be resolved, and older tokens remain valid. Failures return a nonzero exit status. Test state is created only in a temporary directory and removed afterward.

`bash tools/e2e-acceptance.sh` is the mandatory **PR and release CI** acceptance suite. It fails if Composer dependencies or PDO SQLite are unavailable, rather than silently skipping test sections. It executes the new integration above plus existing integration scripts for:

- Symfony HTTP middleware + real auth, CSRF/RBAC and Integration Center API;
- full SQLite content routing/search/media/rendering and import/export;
- file queue, competing reservations, scheduler, crash and retry paths;
- AI Chat conversation ownership, token budget and rollback with a *mocked* OpenAI transport and real SQL;
- AI Actions operator-approved proposal application using real CMS and SQL.

### Local prerequisites

- PHP >= 8.4.1, Composer 2, extensions in `composer.json`, including `pdo_sqlite`, `mbstring`, `sodium`, `pcntl`, and OpenSSL;
- `composer install --no-interaction --prefer-dist` using the checked-in lockfile;
- Linux/Unix for `pcntl_fork` and process signal tests;
- Python, Node, Nuxt and browser/Playwright dependencies for the **additional** release workflow checks already present in `.github/workflows/release.yml`.

Run from the repository root:

```bash
composer install --no-interaction --prefer-dist --no-progress
bash tools/e2e-acceptance.sh
vendor/bin/phpunit --no-coverage
php tools/ci-check.php --strict
node admin/scripts/publish-admin-assets.mjs --check
php tools/generate-api-sdk.php --check
```

## Scope and limits

These E2E checks do **not** connect to or mutate production data. External AI responses are deterministic, locally mocked by existing integration scripts; they do not prove availability of the OpenAI service. The CI matrix additionally validates PostgreSQL, MySQL/MariaDB and Redis where configured, but the new E2E runner itself uses **SQLite** and **file queue**. Nuxt and bundled static admin asset compilation remain separate CI checks. Real deployment/browser network testing, live OIDC provider behaviour, production traffic, distributed lock correctness on multi-host local filesystems, and full environment-specific failover require staging acceptance before general availability.

### Recovery

No migration is required. When deploying this patch, stop PHP workers and replace the changed application files atomically. Do not delete `storage/app/cms/preview-tokens.json` or its sidecar lock file. Existing nonexpired preview tokens remain valid. Check filesystem ownership and run `php tools/e2e-content-preview-regression.php` in an isolated environment after updating.
