# CajeerEngine 1.8.1 — Release baseline and CI gates

This is a stabilization release. It contains no database migration and does not change the CMS API version (`engine_api_version = 1.1`). Deploy it over 1.8.0 with the normal backup, application deployment and service restart procedure. Do not reapply prior migrations manually; use the project's migration runner.

## Required environment

- PHP 8.4.1+, Composer 2 and the PHP extensions from `composer.json`; enable `proc_open` for CLI quality checks.
- Node.js 22 for the Nuxt admin build and TypeScript SDK compilation.
- The checked-in `composer.lock`; CI does **not** update production dependencies automatically.
- Real database and browser-service fixtures for the full release workflow; the PR baseline is intentionally narrower.

## Local quality gates

Run from the project root:

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
composer validate --strict
composer check-platform-reqs
php tools/ci-check.php --strict
php tools/generate-api-sdk.php --check
php bin/cajeer api:lint --strict --json
vendor/bin/phpunit --no-coverage
composer analyse
node admin/scripts/publish-admin-assets.mjs --check
npm install --prefix admin --no-audit --no-fund
npm run build --prefix admin
npm install --prefix packages/ts-sdk --no-audit --no-fund
npm run build --prefix packages/ts-sdk
php tools/build-release.php --dry-run --all --json
```

`ci-check.php` now invokes the **real PHP CLI parser** (`php -l`) for the PHP source, tests, deploy scripts and executable entrypoints, not `token_get_all`. If the process cannot be launched, the syntax gate fails closed. `VersionVerifier` checks the application, SDKs, published admin manifest, displayed static admin version and source/dist download names against `VERSION`.

## GitHub Actions

- `.github/workflows/ci.yml` runs the non-production baseline on push to `main` and pull requests, including the full PHPUnit suite, PHPStan and both UI/SDK builds.
- `.github/workflows/release.yml` runs the same mandatory quality gates **before** database, browser, deployment and artifact certification, followed by release signing/publishing on version tags.
- `tools/release-pipeline.sh` checks SDKs, assets and release audit again in the clean Git snapshot after `composer install --no-dev`; it never uses local dirty runtime files to build the artifact.

The full release workflow still requires working external services, a compliant PHP environment, access to locked package dependencies and signing credentials for tag publication. A local successful source audit is not proof of a production deployment. For missing local `vendor/`, the non-strict auditor can report warnings about dist requirements, but `--strict` is required in CI and the release pipeline.

## API and SDK alignment

The seven AI Automation operations added in 1.7.8 are now included in `api/openapi.yaml` with browser-session/CSRF requirements, optimistic revision checking and request/response shapes. Regenerate only through `php tools/generate-api-sdk.php`; CI uses `--check` to reject stale generated PHP and TypeScript operations.
