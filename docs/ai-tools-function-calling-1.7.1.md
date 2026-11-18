# CajeerEngine 1.7.1 — AI Tools & Function Calling

## What is operational

The existing **AI Assistant** now calls real, read-only CMS services through OpenAI Responses API function calling. It receives an actual model-selected `function_call`, validates the `call_id`, JSON argument names/types and current session permissions, executes the CMS service, then submits a matching `function_call_output` to the model for the final answer. The model cannot name arbitrary PHP methods or URLs. All calls run server-side in the currently authenticated admin browser session.

| Tool | Existing service | Minimum permission |
|---|---|---|
| `cms_content_search` | ContentEntryRepository::page | `content:read` |
| `cms_content_get` | ContentEntryRepository::findById | `content:read` |
| `cms_seo_audit` | AiSeoAssistant::audit | `content:read` |
| `cms_diagnostics_run` | AiCmsDiagnostics::snapshot | `system:read` |
| `cms_knowledge_search` | AiKnowledgeBase::search | `content:read` |
| `cms_analytics_report` | AnalyticsHub::report (saved snapshots only) | `analytics:read` |

All results are bounded and screened for credential material before the next upstream request. The model receives no API keys or unbounded raw logs. It cannot change content, publish a page, execute shell commands, edit the database, sync analytics, or perform network requests through a tool. The `cms_analytics_report` tool reads a stored report only; it does not fetch external statistics. Tools and their results are **untrusted evidence** for the model, not instructions. Do not rely on model output without verifying critical conclusions.

## Browser behavior and API

Both Nuxt and production static SPA show the list of tools permitted to the current account (GET `/api/v1/ai/tools`). Users explicitly enable the **Allow CMS tools** switch; it is OFF by default. Both `POST /api/v1/ai/chat` and `/ai/chat/stream` accept optional boolean `tools_enabled` (default false). SSE streams `event: tool` with name/status/duration, followed by `delta` and `done` or `error`. The JSON chat endpoint returns a `tools` array with names and statuses. Conversation history exposes the same tool metadata; it never persists call arguments or tool-result bodies.

Enabling tools authorizes sending selected matching CMS data (including internal documents, if the user's `content:read` scope allows them) to OpenAI for this request. Check your organization's data sharing policy and keep tools disabled when internal content must stay local. OpenAI API billing is separate from ChatGPT subscriptions.

Requests can make at most 4 model round trips and 8 tool calls, with strict provider limits and a per-conversation 600-second generation lease. Tool executions are read-only but can be slow. PHP-FPM and gateway timeouts must accommodate long-lived SSE. Usage from every round trip is charged against the existing user/month AI budget. A conservative budget reservation is made in SQL before any provider request and released after completion or failure; the lease allows recovery from a crashed worker. In addition, `ai.tool.executed` is written to the local audit log, recording only tool name, success/failure and duration.

## Deployment from 1.7.0

1. Back up the database and application storage; deploy the **changed-only ZIP on top of a complete 1.7.0 installation**, not by itself.
2. Run `php bin/cajeer migrate` for `0037_ai_tools.sql` on the active database. PostgreSQL, MySQL and SQLite definitions are provided.
3. Restart PHP-FPM/workers and clear OPcache; `php bin/cajeer admin:assets --check` must pass.
4. Verify `php bin/cajeer api:lint --strict` and `php bin/cajeer ai:acceptance --require-production` against the deployed code. Use `--probe-provider` only when you explicitly want a billable OpenAI test.
5. Open AI Assistant as a user with `content:read`, enable tools, ask it to find a known CMS page or Knowledge Base document, and confirm the tool status and grounded response.
6. Confirm an account without `system:read` cannot use diagnostics, and one without `analytics:read` cannot use analytics reports, even when a request attempts to call them.

## Acceptance and rollback

`php tools/ai-tools-regression.php` runs offline function-call orchestration and deny-path checks. `php tools/ai-tools-integration.php --require-db` runs real PDO SQLite CRUD, the knowledge index, OpenAI mock model calls, durable history, audit and token accounting. Both are required steps in the release workflow. The existing chat browser regression covers the opt-in switch, SSE tool event, stored trace, CSRF and XSS protection.

Roll back PHP and published assets together if the update fails, then restart PHP-FPM. The new `ce_ai_tool_calls` table is additive; leaving it intact during a rollback is safer than deleting audit history. On returning to 1.7.1, rerun the migration/readiness checks. Do not remove any prior AI tables or destroy existing conversations.

**Scope:** 1.7.1 adds *read-only* AI Tools and model function calling. Tool-mediated writes/approvals are explicitly reserved for 1.7.2.
