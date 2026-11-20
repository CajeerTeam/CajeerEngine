# CajeerEngine 1.9.4 — AI Assistant Production

This is a hardening update to **actual provider execution**, not a new AI API facade. The existing CMS Assistant still provides Chat, Content Editor, SEO, Translation Studio, CMS Diagnostics, Knowledge Base, RAG, Support Center, controlled Tools/Actions, cost controls, and owner-scoped background automations.

## Before upgrading

1. Take and verify a database and files backup (`php bin/cajeer backup:create`, `php bin/cajeer backup:list`, `php bin/cajeer backup:verify`); retain the previous 1.9.3 release and the existing credential-vault encryption key.
2. Stop scheduler and queue workers, deploy the changed-only files **over a complete 1.9.3 installation**, preserving `.env`, `storage/`, uploads, vault keys and generated user data. A delta ZIP is not a standalone installation.
3. Confirm there are no incomplete migrations with `php bin/cajeer migrate:status`, then run `php bin/cajeer migrate` if necessary. **1.9.4 contains no new schema migrations.** AI Automation requires the previously published SQL migrations through `0043`.
4. Publish/check the static admin UI (`php bin/cajeer admin:assets --check`), restart PHP-FPM/workers/scheduler and invalidate stale OPcache where applicable.

## Production configuration and acceptance

1. Configure a supported OpenAI model and an encrypted API credential in AI Settings. Do **not** store plaintext credentials in templates or in AI prompts. Configure verified per-model input/cached/output prices and both daily/monthly global/user hard spending limits before enabling paid requests.
2. Check availability of `ext-curl`, `ext-sodium`, OpenSSL, PostgreSQL and the actual job backend. Run `php bin/cajeer ai:acceptance --require-production` on the deployed host. This check verifies AI tables, including schedules/runs, required runtime, decryptability of credentials and published UI assets; it does not make external API calls.
3. Only with explicit approval for a **billable** provider check, use `php bin/cajeer ai:acceptance --require-production --probe-provider`. This sends one real OpenAI Responses request with budget reservation. Ensure the configured model can be used by your account. Do not use this as a recurring health-check.
4. On staging, exercise Chat with multiple turns, read-only tools, a pending AI Action that requires human approval, Content Editor preview (without publication), RAG citations, Support handoff, and one queued automation. Verify history isolation between owners, token/cost reports, controlled failure and worker restart. Reconcile OpenAI-side usage against the AI cost ledger.
5. Confirm the provider request's status and token usage and that interrupted or malformed SSE responses are not persisted as successful answers. If network delivery becomes ambiguous, do not automatically replay the paid provider request; check the associated job and usage records before retrying.

## Changes in 1.9.4

- The **real** OpenAI HTTP transport now parses SSE across arbitrary network chunk boundaries and requires a successful, internally consistent `response.completed` event before returning a successful result. Corrupt/duplicate/incomplete events and invalid token accounting are rejected. Final-only provider output still reaches the live Chat stream.
- Function-calling now validates an entire batch of names, call identifiers and JSON argument objects **before any CMS tool executes**. Repeated call identifiers, duplicate declarations and unknown functions are rejected. Empty `{}` arguments are valid for zero-parameter functions.
- Outbound secret checks also inspect nested JSON-encoded tool output: even short credentials under `client_secret`, `access_token`, `password` and other sensitive field names do not leave the server.
- Preflight exceptions from database limits, authorization and provider configuration wipe the decrypted OpenAI credential before propagating the error.
- AI production acceptance now requires the existing automation tables in addition to Chat/Editor/RAG/Support/Cost tables.

## Mandatory regression tests and limits

Run `php tools/ai-assistant-production-regression.php`, `php tools/ai-tools-regression.php`, `php tools/ai-security-regression.php`, `php tools/ai-ga-acceptance-regression.php --require-db` and the full CI test suites. The new test is credential-free; it operates on the actual stream parser and function-dispatch implementation, including negative cases. CI must have `pdo_sqlite`, Composer dependencies and full server integrations available.

A green offline parser/test run **does not certify live OpenAI billing, identity, production queue processes, source/dist artifact signatures or staging availability**. Approval for GA requires the full release CI and real operator production-host acceptance, including recovery drills. Existing AI tools cannot publish content automatically: operator approval remains mandatory.

## Rollback

Stop workers, restore the 1.9.3 PHP code and matching static assets, restart services and re-run runtime acceptance. There are no new database migrations. Do not reset stored AI ledgers, automation runs, decrypted credentials or signed actions; preserve accounting for calls that may have been charged during a failed request.
