# CajeerEngine 1.9.9 — Production GA Qualification

## Scope and release status

The 1.9.9 milestone hardens the existing production release process. It does **not** authorize a 2.0 stable label, replace CI/staging acceptance, or imply that live Keycloak, analytics providers, external databases or OpenAI have been tested in this source workspace. The release must be qualified against the exact signed `dist` ZIP bytes on the supported Nginx/PHP-FPM/PostgreSQL profile.

## Changes to the running product

**Authenticated recovery imports.** `core/Backup/RecoveryBundle.php` decrypts into a randomly named private sibling directory and validates the complete final authenticated frame before publishing via an atomic rename. If extraction fails (wrong key, tampering, truncation, malformed metadata) the temporary files are removed, and an existing target remains empty or unchanged. A populated destination is deliberately rejected to avoid merging two snapshots. BackupManager still verifies the imported snapshot manifest/MAC before accepting a backup or starting restore. Scratch directories and backing storage must be on a filesystem supporting atomic directory rename. The import path still requires free space for a complete snapshot and the administrator must preserve the original off-host archive.

**Dist/source completeness.** `tools/certification-evidence.py` verifies every runtime file from the qualified source exists in dist and has the same SHA-256, not merely that the common subset agrees. This includes `core/`, `config/`, `migrations/`, `api/`, `resources/`, `templates/`, `installer/`, `public/` and release entrypoints. It also rejects any release metadata whose `stability` disagrees with its SemVer/channel. No signing key is made available until this exact-artifact certification succeeds.

## Reproducible local checks

From the repository root with PHP 8.4 and Python 3:

```bash
php tools/ga-qualification-regression.php
php tools/disaster-recovery-regression.php
php tools/database-recovery-integrity-regression.php
python3 tools/certification-regression.py
php tools/ci-check.php --strict
php tools/build-release.php --dry-run --all --json
```

The first test exercises a real encrypted multi-frame archive, valid extraction, wrong destination, truncation and tampered final authentication. The certification tests create actual ZIP files, rewrite their manifests and assert that a forged stability claim and a dist missing source runtime code cannot pass verification. These tests are wired to both PR and release CI.

## Staging approval — release blockers

1. In a disposable environment, install all **locked** Composer and UI dependencies and run the full PHPUnit/PHPStan, DB compatibility matrix and `tools/e2e-acceptance.sh`; none may be skipped or ignored.
2. Execute the complete source and dist builder, verify the SHA-256 sidecars and manifests, and run `tools/certification-evidence.py verify` after obtaining real production host and dependency audit reports. Do not substitute mock reports for official evidence.
3. On the supported Nginx/TLS/PHP-FPM/PostgreSQL profile, execute a live production-host smoke test using **the same dist ZIP**; record effective security headers, latency, HTTP error rate, connection pool and resource consumption. Run a separate sustained soak/load test with agreed SLOs.
4. Exercise backup restore with realistic data and multiple workers stopped, measure RTO/RPO, check CMS content, media, session revocation, queue state and AI job deduplication, then repeat an update/rollback drill.
5. Run authenticated staging integrations with external analytics providers, SkiF4er ID/Keycloak and opt-in OpenAI billing, and review access permissions and deletion policy. Never put production API keys into test reports.
6. Go/No-Go: no known critical/high findings, no missing required tests, no dist/source mismatch, no unknown rollback result. Capture the signed tag, commit, artifact SHA-256, CI URL and operator sign-off.

## Upgrade and rollback

Back up PostgreSQL, media, application files, encryption keys and environment settings. Stop workers/schedulers before replacing application files, then restart and inspect queue/health dashboards. This change has no schema migration. If rollback is needed, stop services, restore the previous signed application package plus its matching database/files, and verify queues and identity sessions before reopening traffic. See `docs/operations-recovery-1.8.8.md` and `docs/operations-deployment-1.8.8.md` for the established runbooks.
