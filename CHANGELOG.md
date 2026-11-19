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
