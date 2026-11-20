# CajeerEngine 2.0.0 — Production release operations

## Included systems

Production release 2.0.0 retains CMS Core, External Analytics, Migration Center,
SkiF4er ID (Keycloak/OIDC) and AI Assistant, including existing PostgreSQL,
worker/scheduler, backup and recovery integration. This milestone also closes
operational risks in the artifact index and operator support diagnostics.

## Release and installation gates

1. Run `composer install` with locked dependencies and `composer check-platform-reqs`.
2. Run `php tools/production-release-regression.php --require-zip` and
   `php tools/ga-qualification-regression.php`.
3. Run full release CI, including PHPUnit, PHPStan, migration matrices, real
   Nginx/TLS/PHP-FPM/PostgreSQL acceptance and source/dist certification.
4. Build both source and dist: `php tools/build-release.php --all --json`.
   An absent dist produces **no complete artifact index** and is a failed release.
5. Verify both ZIPs and checksum sidecars using
   `php tools/build-release.php --checksums --json` and the certification script.
6. Sign the exact dist, publish via the gated GitHub release workflow and keep
   the immutable artifact digest with the host acceptance report.
7. Back up the live database, media, `.env` and worker state before upgrading;
   install only signed and certified release archives. Check migrations and
   verify routes, SkiF4er ID login/logout, analytics sync, Migration Center,
   AI Assistant budget/authorization and worker/scheduler health after deployment.
8. Confirm restore and rollback in a dedicated staging environment before a
   production change window. Do not invoke destructive restore against live data.

A source ZIP or successful local dry-run is **not** a production certification.
External provider credentials and live staging are required to close the
remaining acceptance gates.

## Privacy-safe diagnostics

`stable:support-bundle` produces a private ZIP with safe status flags and a list
of environment **key names only**; values and comments are never included.
Raw runtime and audit JSONL events (including request bodies, token fields,
identifiers and stack traces) are not included, even when logs are requested.
Optional log summaries contain only bounded counts by severity, not messages.
The default API option is `include_logs=false`.

Keep support bundles inside restricted `storage/app/support`, transfer only
through an approved encrypted channel and remove them according to retention
policy. Never publish them as release artifacts.

## Known qualification boundary

Official GA admission requires successful full CI on a precise commit, signed
source/dist and external staging/production-host evidence. Local regressions
cannot prove safety of a deployment with custom extensions, databases, identity
provider or network restrictions.
