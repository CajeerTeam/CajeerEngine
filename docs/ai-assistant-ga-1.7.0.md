# CajeerEngine 1.7.0 — AI Assistant ChatGPT GA

This release promotes the existing AI workflows (Chat, Content Editor, SEO, Translation, Diagnostics, Knowledge Base, RAG and Support) into a single production-acceptance cycle. It **does not** authorize AI to publish CMS changes or execute diagnostic commands unattended.

## Upgrade from 1.6.9

1. Make a verified database and storage backup (`php bin/cajeer backup:create`) and retain the previous 1.6.9 code/asset release. Stop running workers during code replacement. Check your existing identity/session backup and encryption keys.
2. Deploy the full 1.6.9 tree plus the changed-only 1.7.0 files; never deploy a delta ZIP as a standalone installation. Keep `.env`, saved AI credential vault, `storage/` and uploads out of the release archive.
3. Run `php bin/cajeer migrate` to make sure all migrations through 0036 have been applied. **1.7.0 adds no schema migration**.
4. Publish/check the static admin assets: `php bin/cajeer admin:assets --check`. Restart PHP-FPM and the queue workers, then invalidate OPcache as required by your deployment.
5. On the installed host, run `php bin/cajeer ai:acceptance --require-production`. This read-only acceptance returns JSON and exits with `1` when **any** mandatory prerequisite fails. No API keys, plaintext passwords, or raw provider errors appear in the report.
6. Optionally run `php bin/cajeer ai:acceptance --require-production --probe-provider` **only when paid provider API usage is permitted**. It makes exactly one OpenAI Responses API generation request, checks for response/token usage and reports only success or failure.
7. Verify authenticated Chat, AI proposal generation/review in Content Editor, SEO audit, translation draft approval, offline CMS Diagnostics, private/public Knowledge Base searches, RAG citations and Support ticket handoff in your staging environment. Test a failed provider call and a restarted worker. Do not put live credentials in test tickets or knowledge documents.
8. Check Identity/SSO and security checks separately (`php bin/cajeer identity:acceptance --require-oidc` and the appropriate server doctor command). The AI preflight is **not** a replacement for server, OIDC or HTTP deployment acceptance.

## Security and concurrency behavior

- A Support guest reply, human-handoff request and operator reply now first claims the observed ticket revision **within the same SQL transaction**. If an operator closes a ticket, or another worker changes it first, a stale request cannot append a message or silently reopen the ticket. A SQL error rolls the revision and message back together. On conflict, refresh the ticket and retry intentionally.
- Automated responses keep the existing optimistic revision claim and published-public-source checks; operator handoff and Source revision changes still take precedence.
- RAG conversation deletion is rejected while a generation is active for its owner. Retention skips old RAG conversations while the shared editor/budget lease is active. Expired leases can be recovered by existing mechanisms.
- AI requests still use the protected OpenAI key, shared token accounting, strict source visibility and outbound secret filtering. Private knowledge sources must not be enabled in the public Support chatbot.
- The AI acceptance CLI checks the production environment, HTTPS origin, PHP crypto/cURL prerequisites, published admin manifest, decryptable provider key and all 15 required SQL tables. With SQLite, it also executes `PRAGMA foreign_key_check`.

## Checks in CI

The mandatory `ai-ga-acceptance-regression.php --require-db` test exercises real PHP/PDO SQLite migrations, credential readiness, asset consistency and a broken-schema failure. `ai-ga-sqlite-regression.py` executes SQL revision claims, simulated message-write rollback and active RAG-retention exclusions. Earlier AI Editor, SEO, Translation, Diagnostics, Knowledge, RAG and Support acceptance suites remain required in `.github/workflows/release.yml`.

## Operating limitations

- A passing offline release test **does not certify production**: the live DB/queue, OpenAI billing/network/TLS, Keycloak, HTTP and real browser sessions must be checked in the deployed environment.
- Provider tests consume API credits. Do not turn `--probe-provider` into a frequent scheduled job.
- Migrated databases created before 1.6.9 must still apply their historical schema migrations. For hosted sites, use PostgreSQL where supported and verify the queue worker/scheduler are supervised by the system service manager.
- Archive only changed files for patch delivery; keep external runtime configuration and secret keys untouched.

## Rollback

Stop workers, restore the previous 1.6.9 code and compiled admin assets, restart PHP, and verify existing admin and public routes. Since 1.7.0 has no schema migration, downgrade of SQL schema is not necessary. Restore the verified pre-upgrade database/storage snapshot **only if** a separate migration, data corruption, or destructive operator action requires it; this will roll back newer content and support tickets. Never delete OpenAI vault material or rotate keys as part of a normal code rollback.
