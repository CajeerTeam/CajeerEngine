# 1.8.9 — Release Candidate Qualification

- Strengthened real release certification: inspect ZIP members, manifest/checksums, published admin identity, artifact sidecars and artifact index **before** issuing or accepting a certification.
- Bind source/dist bytes and all packaging sidecars to the immutable checkout and observed production/dependency evidence; reject modification or stale artifacts, duplicate entries and malformed archives.
- Correct checksum-sidecar filename verification in release artifact checks, and accept valid SemVer release-candidate identifiers in version checks.
- Run executable qualification regressions in pull-request and release CI, including negative tests for corrupted archives, sidecars, and incorrectly tagged releases.
- Existing operations runbooks remain under their 1.8.8 filenames and are validated against version 1.8.9; no database migration required.
- Candidate is **not** certified until the full CI, production host, dependency audit and signing steps succeed on real infrastructure.

# 1.8.8 — Documentation & Operational Readiness

- Verified operational handbook covering installation, signed updates, backup/restore drills, monitoring, incident response, secrets and on-call acceptance.
- Executable runbook documentation contract checks (Markdown links, CLI command names, env configuration keys, mandatory sections) integrated into release and PR gates.
- Corrected version drift in `.env.example`, deployment example commands and published release metadata. No database migrations.
- See [Operations Handbook 1.8.8](docs/operations-handbook-1.8.8.md) and [Operational Acceptance](docs/operations-acceptance-1.8.8.md).

# 1.8.7 — Deployment & Recovery Acceptance

- Persist operator update/backup request state **before** side effects (`running`, `completed`, `failed`). Interrupted requests are never retried without reconciliation; failures retain a durable terminal status without including sensitive exception text.
- Make signed local release planning independent of remote catalog availability, so operators can prepare verified recovery updates during an upstream outage.
- Bound backup, restore and verification subprocess runtime with `BACKUP_COMMAND_TIMEOUT_SECONDS` (default 3600, configurable 1–86400). Terminate timed-out subprocesses; use `flock --no-fork` when holding the child recovery lease.
- Verify the restored live SQLite database integrity (or database readiness for PostgreSQL/MySQL) and the recovered `.env` digest before reporting successful restore.
- Add executable SQLite recovery, secret-key rejection, process-timeout and operator-request replay acceptance to PR/release CI, along with existing source/dist and cold-recovery gates.
- Deployment and rollback procedure: [Deployment & Recovery 1.8.7](docs/deployment-recovery-1.8.7.md).

# 1.8.6 — End-to-End Regression

- Fix file-backed CMS preview token lost updates: issue and usage writes now share a stable cross-process lock, with expired-token pruning and fail-closed handling. Expired database tokens no longer receive fresh usage timestamps.
- Add executable CMS lifecycle integration covering draft isolation, token preview, publication, public route visibility, search updates, revision conflicts, unpublish, static export and concurrent preview requests. Test real file storage locally and PDO SQLite with fully applied migrations in CI.
- Require cross-module acceptance in both pull-request and release CI: real Symfony HTTP middleware, auth/CSRF and integration API, SQL-backed routing/search/media/rendering/import, file queue/scheduler crash recovery, AI Chat and approved AI Actions. Each test uses real platform code, isolated state and a locally simulated external AI provider; no external production writes.
- Keep API/SDK operation signatures unchanged and synchronize version across runtime, admin, SDK, OpenAPI and release artifacts. No SQL migration required.
- See [End-to-end acceptance](docs/e2e-regression-1.8.6.md) for coverage and limitations.

# 1.8.5 — Performance & Load Testing

- Prevent public CMS searches from blocking concurrent publication writes: count/facets/page now share a repeatable-read database snapshot instead of a shared publication-row lock.
- Avoid public-page cache stampedes with per-route locks and post-lock revalidation, while preserving content-addressed invalidation, output limits and exception propagation.
- Reduce PostgreSQL queue recovery scans during active work from every reservation to bounded periodic checks, without losing idle recovery or failure reporting.
- Add executable 12-process cache contention, PostgreSQL queue SQL query-count, real PHP PDO SQLite search snapshot and local HTTPS load harness regressions; enforce in PR/release CI.
- Add HTTPS-only read-only staging load runner with concurrency, warmup, route-level p50/p95/p99 latency, RPS, HTTP/transport error counts and fail-closed SLO thresholds. Document how to run staging certification. No migrations or API changes.

# 1.8.4 — Database & Compatibility

- Apply the **unchanged published migration SQL** on MySQL 8.4 and MariaDB 10.11: the migration executor resolves legacy `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` using `information_schema`, skips existing columns and executes individual statements without enabling PDO multi-statements. Previously applied migration checksums remain valid.
- Refuse migration runs if the requested SQL dialect does not match the actual PDO driver. Read each migration once, using identical bytes for execution and SHA-256 ledger checks.
- Report altered or missing already-applied migration files in `migrate:status` and database migrations API; CLI exits nonzero on integrity failures.
- Validate database connection charset and PostgreSQL TLS mode. Add regression/fault tests and full isolated-schema migrations on PostgreSQL, MariaDB and SQLite in release CI.
- No new migrations or deliberate breaking changes to the existing API/SDK. See [Database compatibility operations](docs/database-compatibility-1.8.4.md).

# 1.8.3 — Runtime & Queue Resilience

- Isolate scheduler task execution and state inspection: a failed queue cleanup or corrupt task ledger cannot prevent other due tasks from running.
- Surface queue reservation failures, acknowledgement uncertainty and diagnostic outages as structured worker failures. Do not deliberately call `fail()` after the handler has already succeeded.
- Return HTTP 503 + Retry-After for unavailable queue operations and scheduler runs; make CLI commands fail in both human and JSON modes when jobs or scheduling fail.
- Redact handler/transport exception details from worker results and scheduled task state.
- Repair the pre-existing queue regression fixture to use the HTTPS webhook requirement introduced in 1.8.2. Add real file-queue fault injection to both PR CI and release CI.
- No new database migrations. Install only with drained, compatible 1.8.2 workers; see `docs/runtime-queue-resilience-1.8.3.md`.

# 1.8.2 — Security Hardening

- Prevent cross-user API-token revocation with an atomic owner-constrained UPDATE; browser sessions cannot be revoked through the API-token endpoint; missing session registries fail closed and new session TTLs are capped at 30 days.
- Validate new API-token names, scopes and expiry times before writing tokens; disable caching of token-management responses.
- Require HTTPS for webhook destinations; reject user-info, fragments, non-public IPs, unsupported ports and malformed events.
- Require a new webhook signing secret when the receiver changes; allow explicit secret rotation and show secrets only once at creation.
- Stop returning webhook signing secrets on updates; reject insecure pre-upgrade webhook destinations at delivery time; prevent remote response bodies, query credentials and transport exception details from entering delivery history.
- Hide internal database exception details and raw runtime errors in production API responses.
- Add runnable security regression tests and mandatory CI security-hardening gate. [Operator migration and acceptance guide](docs/security-hardening-1.8.2.md).

# 1.8.1 — Release Baseline & CI Stabilization

- Synchronize the CMS, release metadata, PHP/TypeScript SDKs, Nuxt and deployed static admin UI to 1.8.1.
- Restore AI Automation OpenAPI operations with explicit authorization and request schemas; regenerate both executable SDK operation registries.
- Replace PHP tokenization with actual PHP CLI syntax lint; enforce real syntax verification in CI and release builds.
- Run the full PHPUnit suite, static analysis, admin build, release audit and SDK checks as release-blocking quality gates.
- Add baseline regression tests for version drift, missing API routes, syntax failures and admin asset mismatches.

# 1.8.0 — AI Automation GA

- Reject forged queue deliveries before they reach the billable OpenAI path; verify job identity and one-attempt policy.
- Fix deterministic IDs and `_max_attempts=1` in the standalone RabbitMQ driver (still deliberately disabled by `QueueFactory`; supported GA transports remain file/Redis/PostgreSQL).
- Add `AI_AUTOMATION_ENABLED` emergency stop for new and pending AI executions.
- Erase schedule and execution prompt copies immediately upon soft deletion; recheck account and deletion state before provider calls.
- Continue DB scheduling when the queue failed-job listing is unavailable; report transport degradation separately.
- Use bounded retention sweeps (250 rows per pass), backlogged-run counters and dedicated SQL indexes.
- Add regression tests and release CI gates; GA documentation clarifies at-most-once vs guaranteed execution.

# 1.7.9 — AI Automation Security & Acceptance

- Serialize automation count and 24-hour execution quotas per owner across concurrent hosts using row-level database locks.
- Reconcile dead-letter queue jobs, expire undelivered runs after 24 hours without invoking OpenAI, and preserve at-most-once provider execution.
- Distribute queue re-offers fairly using persisted `last_offer_at`, including across transport outages.
- Preserve committed manual runs when queue insertion fails and surface `enqueued: false` to the operator; scheduler can restore delivery.
- Enforce active-account and permissions checks at dispatch and queue claim; reject scheduled deliveries paused before execution.
- Redact downstream provider exceptions in queue status, and clear completed prompt snapshots after 30 days.
- Expand PHP acceptance coverage for revocation, transport failures, queue exhaustion, stale runs and privacy boundaries.

# 1.7.8 — AI Background Automation

- Owner-isolated scheduled and one-off OpenAI prompts with real queue workers and persistent per-run history.
- Scheduler-driven dispatch, durable queue idempotency, atomic worker claims and interruption handling without unsafe automatic provider retries.
- AI Chat conversation output with existing token limits, budget reservations and USD cost accounting.
- Admin UI for creating, editing, pausing, launching and inspecting schedules (Nuxt and static production UI).
- Three-database migrations, RBAC rechecks, audit events and integration coverage.
- [Operations and API guide](docs/ai-background-automation-1.7.8.md).

# 1.7.7 — AI Budget & Cost Control

- Integer USD-micro cost ledger with snapshotted per-model input, cached-input and output prices.
- Atomic per-user and installation daily/monthly spending caps, reservations and crash reconciliation.
- Admin billing dashboard and module/model/user breakdown with 50/80/100% budget warnings.
- Existing chat, editor, translations, knowledge chat, support, diagnostics, analytics and migration requests now settle cost and token budgets together.
- Unknown model pricing fails closed until an administrator configures rates.

# 1.7.6 — AI Migration Remediation

- Восстановление отсутствующих 301-редиректов только по сохранённым подтверждённым SEO-картам миграции с проверкой существующих маршрутов и целевых опубликованных страниц.
- Подтверждаемая пересборка повреждённого индекса публичных маршрутов; недоступные или конфликтующие исправления исключаются.
- Предложения с TTL, явным подтверждением и отклонением, транзакционной защитой от повторов, журналом и проверкой результата.
- Nuxt и production static SPA, SQL-миграция 0039, API/SDK, регрессии и инструкция по откату.
- Медиа и редакторские изменения не изменяются автоматически без достоверного источника.

# 1.7.5 — AI Migration Copilot

- Реальная read-only диагностика проектов DLE/WordPress по сохранённой операторской сверке и текущим данным назначения: редиректы, legacy query URL, медиа SHA-256 и индекс публичных маршрутов.
- Приоритизация проблем, проверяемые рекомендации, предупреждения о неполном покрытии и об устаревшем отчёте; без подключения к исходной БД или изменения CMS.
- AI-объяснение через OpenAI Responses API на основе обезличенных агрегатов с резервом токенов, rate limit, RBAC, CSRF, аудитом и очисткой ключа из памяти.
- Рабочий Nuxt-интерфейс, панель в production static SPA, экспорт JSON, API/SDK, локальные regression-тесты и документация.

# 1.7.4 — AI SEO & Content Insights

- сопоставление сохранённых URL из Analytics Hub с опубликованными маршрутами CMS; проверка происхождения URL по `APP_URL`;
- достоверные сравнения страниц одного провайдера в равных периодах, предупреждения о семплировании, ограничениях и устаревших отчётах;
- приоритизация реальных SEO-замечаний и подтверждённой динамики, без утверждения причинности;
- безопасная генерация предложений SEO Title и Description через существующий AI Editor, ручное утверждение с ревизиями;
- новые API-маршруты, Nuxt/static SPA, регрессии и документация.

# 1.7.3 — AI External Analytics

- Read-only numeric analytics insights from real Analytics Hub snapshots for each connection, including provider-specific period comparisons, quality warnings, 7-day trend signals and bounded channel/page breakdowns.
- On-demand OpenAI explanations receive numeric evidence only, never resource credentials, raw provider responses, page paths or account identifiers. Shared token reservation, rate limiting and audit apply.
- Working `cms_analytics_insights` function tool, API operations, Nuxt UI and production static SPA; deterministic and browser regressions.
- Existing history, API integrations and sync workers remain unchanged; no new SQL migration is necessary.

# 1.7.2 — AI Actions & Approvals

- Реальные AI-действия: создание черновика, изменение SEO-метаданных, обновление статуса обращения. Сохранение предложения, предварительный просмотр, ручное подтверждение, отклонение, CAS по ревизии и аудит.
- Раздельное включение чтения CMS и подготовки действий в обеих админках. Новая SQL-миграция 0038.

<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'">
  <meta name="robots" content="noindex,nofollow">
  <title>CajeerEngine Admin 1.7.2</title>
  <link rel="stylesheet" href="/admin/assets/admin-app.css">
</head>
<body>
  <noscript>Для CajeerEngine Admin UI нужен JavaScript.</noscript>
  <div id="app"></div>
  <script type="module" src="/admin/assets/admin-app.js"></script>
</body>
</html>

# 1.4.8 — Migration Execution & Recovery (historical entry)

- Checkpointed DLE and WordPress imports, resumable batch journal and backup-based rollback.

# 1.4.5 — Users & Identity Migration (historical entry)

- Transactional account and role migration, author associations and operator-driven activation.
