# CajeerEngine 1.7.7 — AI Budget & Cost Control

## Production deployment

1. Create a verified database backup and retain the previous release.
2. Apply the changed-files package over the complete **1.7.6** installation.
3. Run `php bin/cajeer migrate` (migration `0040_ai_cost_control.sql` for SQLite, PostgreSQL and MySQL).
4. Restart PHP-FPM and queue workers / reset OPcache. Validate `php bin/cajeer api:lint --strict` and `php bin/cajeer admin:assets --check`.
5. Open **AI Assistant → AI Budget & Cost Control** with a browser administrator having `system:read`.
6. Users with `system:write` **and** `integrations:secrets` (plus recent step-up) may edit budgets and prices in OpenAI settings.
7. Generate a small real AI request, verify the `ce_ai_cost_events` row, the monthly/day balances and that no reservation remains.

**There is no automatic historical financial backfill.** This release starts recording actual model-token costs when applied. Prior `ce_ai_usage` rows retain only token totals.

## Rates, units and limits

- All money is recorded as integer **micro-USD** (`1 USD = 1,000,000 micro-USD`); API settings accept whole **USD cents** for daily/monthly budgets and model prices per **one million tokens**.
- A price profile specifies `input`, `cached`, `output`. Cached tokens are a subset of input tokens. The transport reads `response.usage.input_tokens_details.cached_tokens` and distinguishes cached from uncached input. Tool-loop usage is aggregated over every Responses API roundtrip.
- The request reserves the entire estimated token ceiling multiplied by the highest relevant configured token rate **before** the outbound call. Daily and monthly budgets are enforced atomically for the whole installation and each user.
- The reservation and the existing token-budget lease are in one SQL transaction. On successful provider completion, the response usage is recorded under the pricing snapshot held at request start, in the same transaction that commits the CMS result. A failed request with no usage releases the reservation. A response that was billed but rejected by CMS validation is still recorded.
- Defaults: whole installation **$100/month, $20/day**; per user **$20/month, $5/day**. The value `0` explicitly disables an individual limit. Existing token limits remain active.
- Preset model prices included in the code are **operator-reviewable snapshots**, not a live feed. They can become outdated. Administrators must verify pricing at <https://developers.openai.com/api/docs/pricing> and update it as needed. Unknown models are **blocked** until an input/cached/output price has been explicitly configured.
- Long-context, regional, priority-tier and additional paid tool prices are **not** inferred. The ledger is an estimate, **not** the OpenAI invoice. Do not treat its limits as a guarantee over charges that cannot be measured or predicted. For hard provider-side financial isolation, also configure OpenAI account/project limits where available.

## Accounting & alerts

- `GET /api/v1/ai/costs?days=30` (1–366 days) requires a browser session with `system:read`. It returns daily/monthly global balances, per-module/model/user totals, a recent ledger and warnings at 50%, 80% and 100% usage **including active reservations**. It exposes no prompt text, response text or credentials.
- All modules that use the shared AI token-budget lease, including editor, translation, knowledge/RAG, support, external analytics, diagnostics and migration copilot, are accounted for. Chat and CMS Tool loops use a separate conversation lease that is accounted in the same ledger. The operator-triggered OpenAI acceptance probe is also accounted.
- Costs for active operations appear as reserved; after settlement they appear as used. The monthly and daily totals have different periods, so **do not add them together**.
- An abandoned lease is **not silently forgiven**. When its expiry is reached, the estimate is transferred to a ledger entry with `state=unverified`; this is conservative until the operator reconciles with provider billing. Reconciliation is performed opportunistically on future AI requests and by `php tools/ai-cost-reconcile.php --limit=50 --apply` from scheduled maintenance. Preview without `--apply` is read-only. The command only expires leases; it cannot know the exact provider invoice.
- Do not delete ledger rows to lower costs. Backup `ce_ai_cost_events` and `ce_ai_cost_balances` with the rest of the database.

## Verification

```bash
php tools/ai-cost-regression.php
python3 tools/ai-cost-sqlite-regression.py
php tools/ai-cost-integration.php --require-db
python3 tools/ai-cost-browser.py
php bin/cajeer api:lint --strict
php bin/cajeer admin:assets --check
```

Release CI runs the real PDO SQLite integration with `pdo_sqlite`, the browser tests with Chromium and the existing AI acceptance regressions. An integration environment with PostgreSQL/MySQL and real OpenAI API credentials is still required to certify production installation.

## Rollback

Stop AI producers and workers before rolling back binaries. Keep the new cost tables during rollback or restore an atomic backup of the database from before migration. Older 1.7.6 binaries do **not** enforce dollar budgets. Do not roll back the binaries to 1.7.6 while allowing billable calls unless provider-side budgets are enforced.
