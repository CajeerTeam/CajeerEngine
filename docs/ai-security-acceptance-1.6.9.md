# AI Security & Acceptance — CajeerEngine 1.6.9

## Scope

This release hardens the **existing working AI services**; it does not introduce a new provider, dummy API or a second chat implementation. Applies to AI Chat, Content Editor, SEO, Translation, CMS Diagnostics, Knowledge Chat/RAG and Support Center.

### Outbound OpenAI boundary

`OpenAiClient::complete()` calls `AiOutboundSafety::assertNoCredentials()` **before** initializing curl. Credential-looking values are rejected with HTTP 422, without including the matching value in the exception or a log. This protects against accidentally submitting an OpenAI API key, bearer/JWT token, PEM private key or recognized server secret in chat text, editorial content or a knowledge document. A bounded 100-KB maximum serialized context also applies across AI features. This is a **high-confidence credential detector**, not a comprehensive DLP scanner: do not load untrusted or sensitive personal data into Knowledge Base without an appropriate data-sharing policy and permissions.

Secrets are stored and retrieved through `AiSettings`/the encrypted vault. Diagnostic provider keys are now cleared with `sodium_memzero()` after the call. Do not print `OPENAI_API_KEY`, `INTEGRATIONS_KEY` or full provider requests in runtime logs.

### Public support delivery / failure behavior

Support jobs continue running asynchronously through `ai.support.reply`. Immediately before writing an auto-answer, a transactional SQL compare-and-swap changes the ticket from revision 1/open/needs_human to revision 2/waiting. Only the winner inserts the answer **inside the same transaction**. Retrying a delivered job cannot create a duplicate AI message. A visitor reply or operator intervention changes the revision and prevents stale AI delivery. OpenAI errors leave the ticket for human support.

### AI retention: explicit opt-in

This is an operational command, **not an API endpoint**. It deletes actual rows and transcripts; take and verify a DB backup first.

```bash
# Preview eligible rows (no deletion):
php tools/ai-retention.php --days=90 --batch=100

# Run one bounded deletion batch per category:
php tools/ai-retention.php --days=90 --batch=100 --apply
```

- `--days` accepts 7–3650; `--batch` accepts 1–500. Both have defaults (90 and 100). Preview is always the default; `--apply` is required to mutate data.
- Old **AI Chat** and **Knowledge Chat** conversations are eligible based on last update. Active AI Chat requests and reserved generations are **never** selected.
- Only **closed** support tickets older than the cutoff are eligible. Open, waiting, resolved and recently closed tickets remain available to operators.
- Expired editor and translation proposals are eligible, regardless of chat age. Published CMS entries, KB documents, glossaries, user accounts, usage ledgers and API tokens are never deleted by this command.
- Each category uses its own bounded DB transaction and deletes child messages and their parent together. A failure rolls back the affected category. For larger installations, repeat the command when `more_possible` is true.
- The CLI takes the application's activity lease, refuses to run during maintenance/restore, and never serves through the public API. It must be executed as the CMS runtime owner with appropriate DB privileges.

Example controlled cron job after backup verification (choose the schedule appropriate for your data policy):

```cron
35 3 * * 0 cd /srv/cajeerengine && /usr/bin/php tools/ai-retention.php --days=90 --batch=100 --apply >> /var/log/cajeer-ai-retention.log 2>&1
```

**Rollback:** deleting aged data is irreversible without the pre-operation DB backup. Do not roll back application code while leaving an incompatible schema; version 1.6.9 adds no SQL migration, so rollback to 1.6.8 restores the previous code without a migration downgrade.

### Acceptance / production release gates

Run in a CI or staging environment with PHP 8.4+, PDO SQLite, `ext-curl`, Composer dependencies, a real database and both administrative UIs:

```bash
php tools/ai-security-regression.php
php tools/ai-retention-integration.php --require-db
php tools/ai-support-integration.php --require-db
php tools/ai-knowledge-chat-integration.php --require-db
php tools/ai-knowledge-integration.php --require-db
php tools/ai-editor-integration.php --require-db
php tools/ai-translation-integration.php --require-db
php tools/ai-seo-integration.php --require-db
php tools/ai-cms-diagnostics-regression.php --require-db
php tools/ai-chat-integration.php --require-db
python3 tools/ai-support-browser.py
php tools/build-release.php --dry-run
```

Before production: confirm DB migration status and a tested backup; exercise a real OpenAI request with a low monthly token budget; verify streaming chat, citation/source revocation, support guest capability checks, operator handoff, 429/5xx provider failures, CSRF and RBAC in the deployed HTTPS environment. Ensure `SUPPORT_AUTO_REPLY_ENABLED=0` until the support queue and its operator monitoring are confirmed. Provider credentials are never checked into the repository. Confirm retention behavior using dry-run before enabling cron.

No actual production credentials, production OpenAI requests or deployed end-to-end smoke tests are included in the repository acceptance suite.
