# CajeerEngine 1.9.0-rc.1 — Release Candidate acceptance

This is a **prerelease candidate for 1.9.0**, not a stable 1.9.0 production release. Its `release.json` channel is `rc`; the GitHub Release MUST be marked `prerelease`. Do not add an unpublished candidate to the bundled stable update catalog. Use staging/test data for the destructive acceptance run.

## Immutable build and CI gates

The actual commit and both ZIP digests must match the evidence in `certification.json`. A local source-lint pass or a synthetic fixture does not certify production readiness. In CI (`.github/workflows/release.yml`):

1. Use a clean tagged checkout `v1.9.0-rc.1`, install locked dependencies and execute full PHPUnit, PHPStan, API/SDK, migration, browser E2E, security, queues and recovery gates. Do not bypass a failed job.
2. Build reproducible source and dist ZIPs from that checkout. Verify manifest inventories, canonical checksums, admin assets, OpenAPI identity and that every common source/dist application file has the same SHA-256. Run `python3 tools/certification-regression.py`, `php tools/release-regression.php` and `php tools/update-regression.php` to exercise negative cases.
3. Deploy the actual dist ZIP to disposable Nginx/TLS/PHP-FPM/PostgreSQL infrastructure; generate `production-acceptance.json` from the real host. Check p95, RPS, security and route semantics. The host's report must identify the actual dist SHA-256.
4. Audit Composer locked runtime dependencies; no ignored critical/high advisory may pass the gate. Seal and verify the whole artifact index, sidecars and operational report with `python3 tools/certification-evidence.py seal <directory>` then `verify <directory>`. Certification includes the checkout commit SHA.
5. After certification passes, sign the exact bytes with the offline Ed25519 release key, upload a **draft prerelease** to GitHub, compare uploaded asset digests and require the remote prerelease flag before promotion. Never overwrite a previously published tag.

## Go / No-Go

**Go only if:** full CI passes on the exact tagged checkout, both signed ZIPs are reproducible, the actual dist passes deploy/restore/rollback and the measured security/load gates, there are no blocking open defects, and operators have approved the evidence. Store the signed artifact SHA-256, commit SHA, dependency audit, staging report, Go/No-Go decision and recovery test evidence outside the CMS.

**No-Go if:** any check is skipped without approval, the dist cannot be built, a migration/recovery fails, remote artifact digests differ, prerelease is published as stable, or the CI evidence is synthetic/stale/missing.

For operator procedures use [handbook](operations-handbook-1.8.8.md), [deployment and rollback](operations-deployment-1.8.8.md), [backups and recovery](operations-recovery-1.8.8.md), [monitoring](operations-monitoring-1.8.8.md), and [incident response](operations-incidents-1.8.8.md). The stable tag `1.9.0` must be a separate release after approval; it is **not** produced by the RC tag.
