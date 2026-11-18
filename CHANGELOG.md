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
