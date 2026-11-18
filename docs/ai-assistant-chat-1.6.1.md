# CajeerEngine 1.6.1 — OpenAI Integration & AI Chat

## Supported workflow

Admin → **AI Assistant** (both Nuxt `admin/pages/ai.vue` and published static SPA). Existing browser session and `content:read` are required. A user can create a conversation, receive a **real Responses API SSE stream**, re-open past history, rename or delete it. Responses and user turns are persisted in SQL only after the upstream completes successfully. A failed upstream request leaves the conversation unchanged. Output is displayed as plain text (not executable HTML).

Configuration requires `system:write` **and** `integrations:secrets`, active cookie session and the existing fresh Identity Center step-up flow. The operator enters an OpenAI API key, model, output-token cap and monthly token limit. Keys are sealed with the same versioned XChaCha20-Poly1305 vault as integrations and are never returned to the browser. Alternatively, configure `OPENAI_API_KEY` in the server environment; the saved encrypted key takes precedence, and removing it falls back to the environment key. The key stored in `storage/app/ai/settings.json` is included in `keys:rekey` operations. Keep old `INTEGRATIONS_PREVIOUS_KEYS` until rotation is verified.

**ChatGPT subscription is not an API credential**: an independent OpenAI Platform API key, with API billing/credits and network connectivity to `api.openai.com:443`, is required. Default model is `gpt-4.1-mini`. The Responses API request specifies `store: false`; chat history resides in CajeerEngine, not OpenAI hosted Conversations. Models may have different prices; token budgets are **not monetary hard limits**. Requests must not include secrets or personal data unless the operator intends to process such information via the provider.

## Upgrade from 1.6.0

1. Make a verified database and `storage/app` backup, then overlay only the changed files on the full 1.6.0 installation. Deploy `VERSION`, `release.json`, both admin asset copies and the manifest together.
2. Deploy SQL migration `0031_ai_chat.sql` using `php bin/cajeer migrate` (PostgreSQL / MySQL / SQLite). This is **required** before enabling AI Chat.
3. Ensure PHP 8.4+, `ext-curl`, `ext-sodium`, PDO driver, outbound TLS root CA and working DNS. Configure `APP_URL` for HTTPS in production. Disable outbound proxying of credentials. If configuring the API key in the UI, configure a valid 32-byte base64 `INTEGRATIONS_KEY` or `APP_KEY`, for example from the existing `keys:generate` workflow.
4. Open `/admin/#/ai` on the prebuilt SPA or `/admin/ai` in Nuxt, sign in, perform Identity Center step-up, set the key and chosen model. Test a short query, open the saved conversation, rename it and delete it.
5. Confirm `api:lint --strict` and `admin:assets --check`. Run `php tools/ai-vault-regression.php`, `php tools/ai-chat-integration.php --require-db` and `python3 tools/ai-browser-regression.py` in a CI/staging environment. Test real OpenAI credentials in staging separately; the repository tests never send real secret credentials to OpenAI.
6. For SSE streaming behind Nginx, preserve `X-Accel-Buffering: no`, disable proxy buffering for `/api/v1/ai/chat/stream`, and allow at least 95 seconds upstream read timeout. Never cache AI endpoints. PHP-FPM must allow at least 95s execution time (the OpenAI outbound request times out at 90s).

## Operations and failure semantics

- OpenAI transport is fixed to `https://api.openai.com/v1/responses` (no configurable target), uses public DNS validation, address pinning, verified TLS, no redirects/proxies, timeout 90s, maximum upstream response bytes 1 MiB and maximum output text 64 KiB.
- Per-user request rate is 12 generation attempts/minute; global existing IP limits still apply. Monthly token allowance defaults to 250,000 actual input + output tokens, with conservative reservation before calls. Failed requests release reservations. If a PHP worker dies mid-call, the next request by that user reclaims reservations after the 180s lease expires. The system does not attempt to charge or refund external API bills on network failures.
- Generations for one conversation cannot overlap. A message is committed only after a completed Responses API event with usage metadata. History is user-isolated, 20 recent turns are sent as context, and the input context is bounded to 48 KiB.
- The browser receives `event: delta`, `event: done`, `event: error` frames; the non-streaming fallback is `POST /api/v1/ai/chat`. An error after some deltas means **no message was committed**, and the browser should retry from its editable prompt.
- Missing key, invalid model, quota exhaustion, network/TLS failures or OpenAI HTTP 429/5xx do not affect any CMS editor or publishing route. No automatic retries of non-idempotent generation occur.
- This release includes **chat only**. AI-assisted content editing, SEO, translation, diagnosis and knowledge retrieval are planned for 1.6.2–1.7.0, not falsely advertised in 1.6.1.

### Verification limitations

The included CI tests use a deterministic fake provider for the SQL chat lifecycle and a mocked browser network for UI SSE events. Only a staging environment with an actual OpenAI API key can establish external service connectivity, account billing and production streaming through the deployed reverse proxy.
