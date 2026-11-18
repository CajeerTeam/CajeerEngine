# CajeerEngine 1.6.4 — AI Translation Studio

This release adds **working OpenAI-backed CMS translation**, localization workflows and batch queue processing to the existing 1.6.3 AI Editor, without giving the language model publishing privileges.

## Deploy from 1.6.3

1. Verify a backup of the CMS database, `storage/`, and the original 1.6.3 source. The patch archive contains **changed/new files only** and must be overlaid on a complete 1.6.3 installation.
2. Stop background workers during the deployment and copy changed files to their matching locations. Restart PHP and clear OPcache after deployment.
3. Run `php bin/cajeer migrate`. Migration `0033_ai_translation_studio.sql` creates actual proposal, source-revision link, terminology, concurrency-claim and durable batch status tables in SQLite, PostgreSQL and MySQL.
4. Run `php bin/cajeer api:lint --strict` and `node admin/scripts/publish-admin-assets.mjs --check`. Both Nuxt (`/translations`) and the shipped static SPA (`/admin/#/translations`) provide Translation Studio.
5. Ensure a server-side OpenAI API key is available in **AI Assistant settings** or through `OPENAI_API_KEY`; set a model and `max_output_tokens` adequate for the expected text. Configure ordinary Queue workers for batch translation, for example `php bin/cajeer queue:work --limit=10` under your process supervisor. The worker must use the same database, queue backend, storage and secret settings as the web process.
6. Verify on staging with a genuine OpenAI API key and a localized content type. Translate a page, review the proposal, create the translated **draft**, edit the source and check stale status, then update the translation with a new revision. Check queue workers and budget limits before going live.

## Functionality

- **Create translation:** choose an existing source entry and a different destination locale; POST `/api/v1/ai/translations/proposals` with `type`, `entry_id`, `target_locale`, `update_existing:false`, optional `instructions`. The result is a private, expiring proposal **not** a changed CMS page.
- **Review and approve:** compare original and translated titles plus field/block JSON in the admin. POST `/api/v1/ai/translations/proposals/{id}/apply` with `confirm_published:false` to save a new **draft** in the existing `translation_group`. Source title/slug/status remain unchanged. Locale-specific slug receives the locale suffix; original canonical and parent/page path are not copied to the new language.
- **Update existing localization:** explicitly select `update_existing:true`. Applying checks original source and target revisions and saves as a new CMS revision, retaining target locale and current publication state. Updating a published translation requires `confirm_published:true` plus an explicit UI confirmation. Normal CMS revision history is retained.
- **Glossary:** GET/POST `/api/v1/ai/translations/glossary` and DELETE `/api/v1/ai/translations/glossary/{id}` manage up to 100 terms per content type + source/target language pair. Terms are sent as translation guidance.
- **Localization health:** GET `/api/v1/ai/translations/overview?type=pages&entry_id=...` lists linked versions and marks AI-generated translations as **stale** if the original revision changes. Existing human translations remain visible without fake freshness metadata.
- **Durable batch:** POST `/api/v1/ai/translations/batches` with `type`, up to **20** distinct `entry_ids`, `target_locale`, `update_existing` and optional instructions. Real jobs enter the CMS queue and generate separate owner-checked proposals. GET `/api/v1/ai/translations/batches/{id}` displays item states; GET `/api/v1/ai/translations/proposals/{id}` opens a completed proposal. Queue retries reuse an existing proposal and cannot auto-publish. Each result needs individual editorial approval. Select `update_existing` consistently across the batch (all chosen entries must either already have or not have the target localization).

## Security and limitations

- All Translation Studio endpoints require a valid authenticated **admin browser session**. Read routes use `content:read`; writes require `content:read` plus `content:write`, and the existing middleware enforces CSRF. Glossary and batch mutations are audited. Interactive translation requests are limited to **4/min/user**, batch scheduling **2/min/user**; API key stays on the server.
- The provider receives title, configured text/textarea/richtext fields, text within declared blocks, source/target languages, optional user instructions and terminology. HTML markup, existing URLs, HTML entities and common template placeholders (`{{name}}`, `${VAR}`, `{name}`, `%s`) are split into fixed elements and **not delegated to the model**. Model outputs are mapped back to segments, UTF-8/size/structure checked and validated against the current content type before approval. Untrusted translated content must still be reviewed by an editor.
- A single AI translation is limited to **100 replaceable segments / 14.5 KB** source text; the existing OpenAI model output cap is up to 4096 tokens. Oversized translations and malformed provider JSON fail safely rather than modifying the CMS. Translation is not a guarantee of semantic correctness or terminology compliance. Images, media assets, scripts and file attachments are not automatically translated.
- Proposals are bound to the creating user, content type and original source hash/revision, and expire after seven days. Applying a stale proposal is rejected. Source/target approval claim serializes concurrent acceptances within this workflow. It does not replace the standard CMS backup or general editing concurrency controls.
- Usage is charged against the **existing shared monthly AI token budget**. Generation reserves a bounded estimate; successful responses settle actual input/output usage; exceptions release the lease. Failed queue jobs retry with ordinary queue backoff and show an error instead of returning a fabricated translation.
- Queue worker processes should run with adequate timeout and memory. A source-only installation without Composer can process translation tasks via the native queue CLI fallback. External OpenAI availability and actual model output quality require live staging tests.

## Acceptance checks

```bash
php tools/ai-translation-regression.php
php tools/ai-translation-integration.php --require-db
php tools/ai-editor-context-regression.php
php tools/ai-seo-regression.php
php tools/generate-api-sdk.php --check
php bin/cajeer api:lint --strict
node admin/scripts/publish-admin-assets.mjs --check
python3 tools/ai-translation-browser.py
```

`ai-translation-integration.php --require-db` exercises real PDO SQLite, real CMS storage/revisions and fake deterministic provider output, including glossary, HTML placeholders, ownership, stale proposal denial, published confirmation, token accounting and durable batch queue round-trip. `ai-translation-browser.py` tests the shipped static SPA in Chromium with a stubbed HTTP transport. The release CI enables `pdo_sqlite` and runs both tests as mandatory steps.

## Rollback

Stop workers and writes before restoring the 1.6.3 application files. Existing translation drafts/approved revisions remain ordinary CMS records and can be reverted through CMS revision history. Keep migration 0033 tables during a **code-only** rollback (do not drop tables containing data). For a full transactional database rollback, restore a verified pre-upgrade backup, understanding that it also reverts unrelated writes after backup time.
