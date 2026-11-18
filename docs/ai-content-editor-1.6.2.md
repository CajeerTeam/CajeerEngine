# CajeerEngine 1.6.2 — AI Content Editor

## Install and configure

1. Install the complete CajeerEngine 1.6.1 release, then overlay the changed-files ZIP for 1.6.2.
2. Back up the database and `storage/` directory. Run `php bin/cajeer migrate` (schema `0032_ai_content_editor.sql` for SQLite, PostgreSQL or MySQL).
3. Verify `INTEGRATIONS_KEY` and the OpenAI credential in **AI Assistant**. The API key stays server-side.
4. Ensure the editor has an authenticated browser session, `content:read` and `content:write` scopes and a valid CSRF token.
5. Open **Content** in either the Nuxt or production static administrator UI.

## Actual workflows

- **New draft**: choose a content type and a text field or title, set *Generate*, provide the topic/instructions, wait for the OpenAI response, compare the proposal, then **Transfer to draft**. Use normal **Save** to create the CMS entry. Draft generation does not publish content.
- **Existing entry**: select an entry, choose title, a text/textarea/richtext field, or an existing text field inside a block. Choose Generate, Rewrite, Shorten, Expand, Proofread or Tone. Optionally paste a verbatim selected passage from the saved field. Review the original and proposed result, then **Apply as new revision**.
- **Published content**: applying AI output to an already-published entry requires a separate confirmation; the existing publication state is preserved.
- **Concurrent edits**: the proposal stores the original SHA-256 and expected revision. The apply operation checks the current content and the repository performs compare-and-swap (CAS). A newer edit makes the proposal stale rather than being overwritten.
- **History**: the normal content revision ledger records reason `ai_editor`. Any later rollback is performed through the usual CMS revision tools.

## Supported fields and limitations

- Top-level `title`, `text`, `textarea`, `richtext`, and saved block fields of those three text types.
- The provider does not generate or mutate relational fields, media, slug, publication status, non-text values, or content schema definitions.
- Only the CMS editor may apply a proposal, with `content:read`/`content:write` and a browser session. Other users cannot retrieve or apply someone else's proposal.
- Provider output for richtext is HTML-escaped and wrapped in paragraphs (selected passages are escaped inline); content model validation still runs before saving.
- Proposals are kept for at most seven days and removed opportunistically during generation. Deployments with strict retention needs can periodically delete rows whose `expires_at < UNIX_TIMESTAMP()` (or database equivalent).
- One editor generation per user runs at once. Budget reservations expire after 180 seconds and are reclaimed on the next request if a worker dies. Generations share the per-user/month `ce_ai_usage` table with AI Chat.
- Preview comparisons render as plain text, never as executable HTML. The actual result can contain model mistakes; human verification remains necessary.
- A live request requires a valid funded OpenAI API key and outbound access to `api.openai.com` (PHP `ext-curl`). There is no offline fallback.

## API

- `POST /api/v1/ai/editor/proposals`: generate a proposal. JSON: `{ "type":"pages", "entry_id":null, "field":"body", "mode":"generate", "instructions":"...", "locale":"ru" }`. For a saved block include `block_index` and `block_field`. Returns `data.id`, `data.original`, `data.proposed`, `data.usage`, and `expected_revision`.
- `POST /api/v1/ai/editor/proposals/{id}/apply`: apply a saved-entry proposal. Pass `{ "confirm_published":true }` only after explicit confirmation for a published entry. Returns the updated `data.entry`.

Both routes enforce browser-session auth, CSRF, `content:read`/`content:write`, throttling (generation), owner isolation and audit metadata. Neither route accepts an arbitrary generated field value for save; apply reads the server-side proposal.

## Tests

`php tools/ai-editor-integration.php --require-db` exercises real PDO SQLite, generation, budget accounting, owner isolation, draft proposals, revision update, and stale/duplicate proposals with a deterministic fake provider. The release CI also executes a Chromium browser regression for the static UI. Execute staging end-to-end with a real OpenAI key before a production rollout.

Rollback: restore the pre-deploy code and DB/storage backup together. Existing content revisions written by 1.6.2 persist in revision history; do not discard them casually. Never remove the current encryption key while OpenAI settings still depend on it.
