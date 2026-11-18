# CajeerEngine 1.7.3 — AI External Analytics

## Реально работающий сценарий

1. Настройте источник в **Analytics Hub** (GA4, Метрика, Search Console, Вебмастер, Matomo, Plausible, Cloudflare Edge или Cloudflare Web Analytics), синхронизируйте требуемый период через существующий worker (`php bin/cajeer queue:work --limit=10`). Для сравнения синхронизируйте **также предыдущий период такой же длины**. Источники используют свои секреты в `SecretVault` и не передают их AI.
2. В статической админке выберите **AI External Analytics**, в Nuxt — `/analytics-insights`. Выберите источник, необязательные границы периода и нажмите **Проанализировать**. Без периода берётся последний сохранённый отчёт.
3. Увидите измеренные изменения, пометки о качестве исходных данных, семидневные сигналы (если доступны 14 полных наблюдений), ограниченные срезы страниц, каналов и поисковых запросов. Результат расчёта не требует OpenAI API и не меняет данные CMS.
4. При желании нажмите **Объяснить через OpenAI**. Запрос идёт через существующий защищённый `OpenAiClient`, с общим бюджетом `ce_ai_usage`, отдельным rate limit (3 запроса/мин на пользователя), аудитом и CSRF. Модель получает **только** разрешённые числовые данные без URL, ID счётчика, названий сайтов, токенов и сырых HTTP-ответов.
5. В AI Assistant, после **явного включения CMS Tools**, доступен `cms_analytics_insights(connection_id,from,to)` с теми же детерминированными результатами, привязанными к `analytics:read`. Инструмент не запускает живые запросы к поставщику, не изменяет настройки и не выполняет действий CMS.

## API

- `GET /api/v1/ai/analytics/insights?connection_id=<32-hex>&from=YYYY-MM-DD&to=YYYY-MM-DD` — сохранённые метрики и объяснимые выводы, `analytics:read`. Даты опциональны только вместе.
- `POST /api/v1/ai/analytics/explain` с JSON `{"connection_id":"<32-hex>","from":"YYYY-MM-DD","to":"YYYY-MM-DD"}` — платное необязательное объяснение, `analytics:read`, cookie-сессия и CSRF. Даты также можно опустить обе.

## Пределы интерпретации

- Результаты строго из **одного** Analytics Hub connection и **одного** набора совместимых метрик. Визиты, пользователи, сессии, клики и просмотры разных систем **не суммируются**.
- Сравнение отображается только при `comparison_status=ready` и достаточном качестве overview. `missing`, `incompatible` и `incomplete` сопровождаются предупреждениями. Сигналы по дням требуют 14 последовательных дат, 7 + 7; они сравнивают **средние ежедневные значения**, а не количество уникальных людей за две недели.
- `sampled`, `thresholded`, `data_loss`, `truncated`, недоступная детализация, старый снимок и ограничения провайдера явно отмечаются. Сигналы не являются доказательством причинности и не инициируют исправления без решения пользователя.
- Исходные документы, пользовательские URL и содержимое поисковых запросов не передаются OpenAI при объяснении. Для локальной страницы срезы ограничены первыми пятью строками, URL query/hash удаляются; HTML экранируется.
- Данные могут отсутствовать до завершения синхронизации. Проблемы провайдера или отсутствие OpenAI не делают основной Analytics Hub или локальные выводы недоступными.

## Обновление, тестирование и откат

Обновите полный исходник 1.7.2 файлами из changed-only ZIP, создайте резервную копию перед развертыванием и перезапустите PHP-FPM (OPcache). Новые SQL-миграции **не нужны**, используется уже существующий Analytics Hub и хранилище бюджета AI.

```bash
php bin/cajeer api:lint --strict
php bin/cajeer admin:assets --check
php tools/generate-api-sdk.php --check
php tools/ai-external-analytics-regression.php
php tools/ai-external-analytics-integration.php --require-db
python3 tools/ai-external-analytics-browser.py
php bin/cajeer release:build --dry-run
```

PHP/PDO SQLite test requires `pdo_sqlite`, and the browser test requires Playwright + Chromium. Staging should also test real provider snapshots, real OpenAI billing, browser session permissions, PostgreSQL/MySQL, an expired snapshot and a provider outage. A local green regression suite does not certify live provider behavior.

To roll back, disable the page/tool in deployment by restoring 1.7.2 files and re-running `admin:assets --write-manifest`; no schema rollback is necessary. Previously synchronized Analytics Hub snapshots remain unchanged. Do not overwrite production connection credentials.
