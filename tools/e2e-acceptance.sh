#!/usr/bin/env bash
# Fail closed: this job executes the real API middleware, migrated SQLite repositories,
# publication/search/preview/static export, queue and AI integrations; no skipped DB tests.
set -euo pipefail
cd "$(dirname "$0")/.."

php -r 'if (!in_array("sqlite", PDO::getAvailableDrivers(), true)) { fwrite(STDERR, "FAIL E2E requires PDO SQLite\n"); exit(1); }'
if [[ ! -f vendor/autoload.php ]]; then
  echo 'FAIL E2E requires installed Composer dependencies (composer install)' >&2
  exit 1
fi

printf '%s\n' '[E2E] CMS editorial lifecycle, preview, search, export: file'
php tools/e2e-content-preview-regression.php
printf '%s\n' '[E2E] CMS editorial lifecycle, preview, search, export: SQLite'
php tools/e2e-content-preview-regression.php --database
printf '%s\n' '[E2E] HTTP middleware, authorization, CSRF, validation and integration API'
php tools/integration-http-regression.php
printf '%s\n' '[E2E] Database-backed public routes, content publication and search'
php tools/routing-regression.php --database
php tools/search-regression.php --database
printf '%s\n' '[E2E] Media and public rendering'
php tools/media-regression.php --database
php tools/rendering-regression.php --database
printf '%s\n' '[E2E] Import and export data round trip'
php tools/import-regression.php --database
printf '%s\n' '[E2E] Real file queue, concurrency, scheduler and outage recovery'
php tools/queue-regression.php
printf '%s\n' '[E2E] AI conversation, security, budget and tool proposal approval (mocked provider, real SQL)'
php tools/ai-chat-integration.php --require-db
php tools/ai-actions-integration.php --require-db
printf '%s\n' '[E2E] Identity: real OIDC store, revocation and SQL sessions'
php tools/oidc-db-regression.php --require-db
printf '%s\n' '[E2E] Analytics: credential-safe reports through real queue worker and SQLite persistence'
php tools/analytics-hub-regression.php --require-db
printf '%s\n' '[E2E] Migration Center: transactional batch import, resume, and revision conflicts'
php tools/migration-execution-regression.php --require-db
printf '%s\n' '[E2E] Recovery: authenticated offsite snapshot, SQL foreign-key consistency and rollback of files'
php tools/database-recovery-integrity-regression.php
printf '%s\n' '[E2E] Completed without skips or failed steps'
