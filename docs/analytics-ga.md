# Analytics & Tracking / Analytics Hub 1.4.0

## Upgrade from 1.3.9

1. Stop workers and scheduler, back up the database, `.env` and `storage/` using the existing backup workflow. Preserve `APP_KEY` / `INTEGRATIONS_KEY` and the backup key. Do not rotate encryption keys during upgrade.
2. Apply release files, run `php bin/cajeer migrate`, then `php bin/cajeer migrate:status`. Migration 0017 creates `analytics_sync_runs` on SQLite, PostgreSQL and MySQL; it does not rewrite or delete existing reports or credentials.
3. Restart workers and scheduler. Visit `/admin/#/analytics-hub`. Existing counter settings, consent policy, integration credentials, advertising rules and all provider connections remain unchanged.
4. Synchronize a source, then open its report and click **Update previous period via API**. Once the worker completes, use **Refresh status**. A comparison only uses the same provider/resource/metrics/timezone and exact prior equal-length period. It never sums distinct users across days or services. Missing/partial/thresholded values remain unavailable; a zero baseline has no percentage. Sampled data is explicitly flagged. Current incomplete calendar days are excluded from comparison. Provider reporting delays can still affect recent historical days.

## Operations

The overview shows connection counts, disabled sources, failures and in-flight synchronization. Each source displays up to 20 recent attempts (current revision), with a stable receipt for successful jobs and retry counts. Up to 500 receipts are retained per connection across revisions. Replays after eviction may fetch again; report writes remain replacement snapshots. Edits fence old workers, failures preserve the last successful snapshot, crashed leases expire after 300 seconds, and one failed enqueue cannot prevent other sources being scheduled. A synchronization returns a queue receipt, not a provider acknowledgement. The worker writes successful report and receipt atomically; max encoded report 4 MiB. Report history is limited to 20 periods per source. Data freshness is the time of retrieval, not a guarantee that a provider's ingestion is complete.

Use `php bin/cajeer queue:work --once` and `php bin/cajeer scheduler:run`; inspect queue diagnostics, failed jobs, and source history. For backups, use the normal encrypted production backup/restore procedure; analytics tables are included in SQL dumps. Restoring a snapshot may restore older jobs and leases, so stop workers during recovery and check consent/configuration before resuming.

## External accounts and privacy

See existing provider guides for credentials and rights. All report sources continue to use SecretVault and the outbound public-host allowlist. No tracking is enabled automatically. Consent-gated scripts cannot govern tags injected independently by themes, plugins, GTM remote configuration or Cloudflare auto-injection. Only use trusted tag configurations; review third-party privacy requirements and retention. Server advertising requires the producer's verified marketing consent and trusted event confirmation. No live provider credentials are shipped.

## Acceptance

Run `php tools/analytics-ga-regression.php --require-db`, `php tools/analytics-hub-regression.php --require-db`, the existing provider/consent/browser/security/backup regressions and `php bin/cajeer api:lint --strict --json`. The release pipeline must pass with real PDO, Composer vendor and browser networking. Before deployment, test each configured external account with test data, confirm tracking and reporting in the provider dashboards, perform the backup restore drill and review privacy requirements. A source build or mocked provider response alone does not constitute production certification.
