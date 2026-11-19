# CajeerEngine 1.8.9 — Release Candidate Qualification

This procedure is for **rehearsal and promotion**, not a declaration that the candidate has passed production acceptance. The candidate is valid only when every required GitHub release job passes on the exact commit and archive digests.

## 1. Preflight (read-only)

On a clean PHP 8.4.1+ checkout with the locked dependencies installed:

```bash
composer install --no-interaction --prefer-dist --no-progress
php tools/ci-check.php --strict
php tools/operations-docs-check.php
python3 tools/certification-regression.py
php tools/release-regression.php
```

Use the PR workflow for the complete PHPUnit, analysis, migration, browser and cross-module E2E acceptance. Do **not** interpret a successful local source-only lint run as a production certificate. In particular, dependencies, PostgreSQL and MariaDB integration, browser E2E and server deploy smoke are required separately.

## 2. Immutable release qualification

Release CI builds source and dist from a clean git archive, audits Composer dependencies, then installs the dist on a disposable Linux/Nginx/TLS/PHP-FPM/PostgreSQL host. The host produces `production-acceptance.json` only after security and load gates pass. The certification step reopens the ZIPs and validates every entry, SHA-256 manifest, `.sha256`/`.manifest.json` sidecar and artifact index. Certification includes the exact digest of all input evidence and the checkout SHA, and is validated again before accessing the signing key. Tampered archives, missing sidecars, wrong version or incorrect report must block promotion.

Do not modify artifacts after qualification. Release signing and publishing must use the exact certified bytes. Use `1.9.0-rc.1` for the next candidate tag only after this stage passes; `1.8.9` is not a published RC unless all gates succeed.

## 3. Hard stop criteria

Stop the release for any failed required CI job, nonzero PHPUnit/PHPStan errors, broken SDK/API consistency, failed DB migration or restore, unsigned or untrusted artifact, failed security or semantic load check, unexplained data loss, unsafe duplicate AI request, non-reproducible build, incorrect checksum or unapproved Critical/High dependency advisory. Retain failure logs without tokens, credentials or customer data. Never bypass a broken check with `continue-on-error` or a fabricated acceptance report.

## 4. Recovery

Use the [operations handbook](operations-handbook-1.8.8.md), [deployment rollback](operations-deployment-1.8.8.md) and [recovery](operations-recovery-1.8.8.md) runbooks. Always validate backups on a disposable instance before production apply; require explicit operator approval for destructive rollback. The deployment acceptance from 1.8.7 remains a required gate in 1.8.9.
