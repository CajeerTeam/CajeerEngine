# CajeerEngine

API-first CMS на PHP с собственным ядром (`core/`), шаблонами `.cjr`, REST API (`api/openapi.yaml`) и статической админкой (`admin/static`, опубликованные файлы `public/admin`). CLI: `bin/cajeer`.

Репозиторий: [GitHub — cajeerteam/CajeerEngine](https://github.com/cajeerteam/CajeerEngine). [Релизы](https://github.com/cajeerteam/CajeerEngine/releases) · [Issues](https://github.com/cajeerteam/CajeerEngine/issues) · [Перенос и публикация на GitHub](docs/github-migration.md).

> **Security & Resilience Hardening 1.5.8:** ротация ключей шифрования с реальным обновлением SQL/файлов, устойчивость интеграций и OIDC. [Эксплуатация и ключи](docs/security-resilience-1.5.8.md).

> **Identity Center 1.5.7:** админское управление локальными SkiF4er ID-привязками и SSO-сессиями, поиск пользователей и безопасная повторная аутентификация: [руководство](docs/identity-center-admin.md).

## AI Assistant ChatGPT GA (1.7.0)

OpenAI Chat, Content Editor, SEO Assistant, Translation Studio, CMS Diagnostics, Knowledge Base, RAG and Support Center are integrated with existing CMS rights, revision history and token budgets. This release hardens concurrent Support ticket updates and prevents RAG history retention while a generation is in progress.

**Before production cutover:** back up DB and storage, apply all migrations through `0036`, run `php bin/cajeer ai:acceptance --require-production` and the existing Identity / security checks. To verify a real OpenAI request, explicitly add `--probe-provider` (billable API usage). Production readiness must be confirmed against the installed deployment; passing offline tests alone does not certify live operation. [GA deployment and rollback](docs/ai-assistant-ga-1.7.0.md).

## AI Security & Acceptance (1.6.9)

Внешние AI-запросы блокируются до передачи в OpenAI, если в контексте обнаружены секреты сервера, ключи API, Bearer/JWT или приватные ключи. Конкурирующие фоновые auto-reply больше не могут создать повторный ответ после передачи тикета оператору.

Команда `php tools/ai-retention.php --days=90 --batch=100` показывает кандидатов на очистку, `--apply` удаляет их транзакционно. Удаляются только старые диалоги, просроченные предложения и закрытые обращения; открытые тикеты и активные генерации сохраняются. Подробности и регрессии: [AI Security & Acceptance](docs/ai-security-acceptance-1.6.9.md).

## AI Support Center (1.6.8)

Публичный виджет поддержки и панель операторов: обращения, переписка, назначение ответственных, статусы и передача человеку. Дополнительные AI-ответы опираются только на опубликованную публичную базу знаний. Включение виджета и автоматических ответов контролируется переменными окружения. [Руководство](docs/ai-support-center-1.6.8.md).

## AI Knowledge Chat / RAG (1.6.7)

Новый административный **AI Knowledge Chat / RAG** находит релевантные фрагменты опубликованных документов и актуальных CMS-страниц через приватный индекс, передаёт выбранные выдержки в OpenAI Responses API только после явного вопроса и возвращает ответ со ссылками на проверенные источники. История привязана к пользователю, используется общий бюджет AI, ссылки повторно проверяются после изменений документов. Включены режим публичных источников, отзывы и управление диалогами. [Эксплуатация и ограничения](docs/ai-knowledge-chat-rag-1.6.7.md).

## AI Knowledge Base (1.6.6)

В админке **AI Knowledge Base** управляет категориями, статьями и внутренними инструкциями. Поддерживаются импорт TXT/Markdown/PDF (PDF требует Poppler), синхронизация опубликованных CMS-страниц, версионированное редактирование, поиск по отдельному SQL-индексу и переиндексация. Внутренний индекс не доступен через публичный поиск, а документы не передаются OpenAI автоматически. [Инструкция по развёртыванию](docs/ai-knowledge-base-1.6.6.md).

## AI CMS Diagnostics (1.6.5)

В админке раздел **AI CMS Diagnostics** предоставляет актуальные проверки БД, storage, очередей, scheduler, cache, search, backup, worker и security hardening. Диагностический отчёт не требует OpenAI; отдельная кнопка отправляет только агрегированные и очищенные показатели в существующий OpenAI Responses API, используя общий токен-бюджет. Подробнее: [руководство](docs/ai-cms-diagnostics-1.6.5.md).

## Установка

- PHP 8.4.1+, mbstring, sodium, Phar, PDO и драйвер выбранной БД, openssl, fileinfo.
- PostgreSQL 17+ для эксплуатации; SQLite для локальной установки и небольших тестовых сайтов. MySQL/MariaDB — дополнительные драйверы, не сертифицированные этим релизом.
- Для полного dist не нужны Composer или Node на сервере. Распакуйте проверенный архив, укажите document root `public/`, настройте права на runtime и откройте `/install` либо запустите `php bin/cajeer install --interactive`.

```bash
php bin/cajeer install --sqlite --interactive
php bin/cajeer admin:assets --check
php bin/cajeer doctor
```

## SkiF4er ID OIDC API Authentication (1.5.5)

A dedicated opt-in OAuth resource server accepts **access** tokens issued for the CajeerEngine API (never ID tokens). It validates signed RS256 JWT claims and optional live Keycloak introspection, links only by verified `(issuer, sub)`, and intersects local RBAC with a configured API permission ceiling. Local `ce_` credentials and browser cookies remain unchanged. See `docs/oidc-api-authentication.md`.

## SSO & Global Logout (1.5.4)

SkiF4er ID uses Keycloak SSO for cross-application sign-on. Registered OIDC browser sessions support RP-Initiated Logout and signed, replay-protected Back-Channel Logout; local password sessions and personal API tokens are not revoked by an IdP logout. See `docs/skif4er-sso-global-logout.md`.

## Unified Session Management (1.5.3)

The Identity page now lists local and SkiF4er ID browser sessions and lets the account owner revoke one, the others, or all. New sessions are registered in SQL; existing active sessions are imported on their next valid request. Session rotation replaces the cookie and CSRF credential. Configure `SESSION_IDLE_MINUTES` (default 720) and run migration `0028_unified_sessions.sql` before using the new build. Back-channel logout is available since 1.5.4; local revocation alone does not terminate a Keycloak SSO browser session. See `docs/unified-session-management.md`.

## SkiF4er ID Account Linking & Provisioning (1.5.2)

The existing OIDC login now supports safe self-service linking from `/admin/#/identity` after local password/2FA confirmation, and optional new-user provisioning with a verified email. Matching email **never** merges accounts. Enable registration explicitly with `SKIF4ER_ID_AUTO_PROVISION=true`; default is disabled. Provisioned users cannot self-unlink until a verified local login alternative exists. Apply SQL migration `0027_oidc_account_linking.sql`. See [Account linking guide](docs/identity-account-linking.md).

## SkiF4er ID Authentication Core (introduced in 1.5.1)

Optional confidential OIDC login with Authorization Code + PKCE, exact SkiF4er ID issuer, JWKS/RS256 validation, encrypted one-time browser transactions, operator-linked accounts and existing local RBAC sessions. Off by default; current local login remains available. See [deployment guide](docs/skif4er-id-authentication.md).

## Production Stable 1.5.0

### Migration Center GA (1.5.0)

The operator-only DLE/WordPress workflow now verifies SQL import receipts and revision provenance, confirms SEO metadata and project-owned 301 redirects, checks media integrity, and refuses cutover on unverified content edits. See `docs/migration-ga.md`.

### Migration Validation & Cutover (introduced in 1.4.9)

After importing DLE or WordPress content and running media/SEO migration, use `php bin/cajeer migration:validate PROJECT_ID` to compare the source with migrated content, users, routes, sitemap and SHA-256 media. If a review includes new/changed records without unresolved blockers, use `migration:sync PROJECT_ID VALIDATION_SHA --yes --batch=50`; it captures a verified backup and saves SQL-atomic checkpoints. Revalidate and activate with `migration:cutover PROJECT_ID VALIDATION_SHA --yes --target=https://site.example.com`, using the configured `APP_URL`. DNS/reverse-proxy changes are an explicit separate operator step. See [Migration Validation & Cutover](docs/migration-validation-cutover.md).

### Checkpointed Migration Execution (1.4.8)

Run `php bin/cajeer migration:execute PROJECT_ID PLAN_SHA256 --yes --batch=50` after approval. Use `migration:status`, `migration:pause`, `migration:resume`, and verified `migration:rollback` (full-site restore, maintenance remains enabled). See `docs/migration-execution-recovery.md`.


Допуск к эксплуатации зависит от прохождения полного release CI, а не от номера версии. [Production certification](docs/production-certification.md): реальные TLS/FPM/PostgreSQL интеграции, нагрузочный профиль, security-приёмка, аудит зависимостей и привязка доказательств к артефактам.

## Разработка и выпуск

Из source: Composer 2 и Node 22. Основная админка — `admin/static`; Nuxt source сохранён для разработки, не используется для production release.

Если в ветке ещё нет `composer.lock`, maintainer должен один раз выполнить `composer update --no-install`, проверить изменения зависимостей и закоммитить lock. Не заменяйте `composer install` на `update` при сборке обычного релиза.

```bash
composer install
node admin/scripts/publish-admin-assets.mjs
node admin/scripts/publish-admin-assets.mjs --check
php tools/release-regression.php
# После коммита всех изменений и composer.lock:
bash tools/release-pipeline.sh /tmp/cajeer-release
bash tools/release-smoke.sh /tmp/cajeer-release/cajeerengine-1.5.0-dist.zip sqlite
```

Сборка использует чистый Git snapshot и проверяет каждую запись ZIP, checksum и sidecar manifest. Dist включает `vendor/`, production Admin assets, миграции и пустые runtime-маркеры. Никакие данные рабочей установки, загруженные медиа, секреты и установленные пользователем плагины не включаются. Публиковать dist только после успешных smoke tests; SHA256 проверяет целостность, но не заменяет подпись доверенного издателя.

## Обновления и восстановление

Подписанный dist применяется отдельным CLI-процессом, под maintenance и эксклюзивной блокировкой. Перед изменением файлов создаётся проверяемый набор восстановления БД и данных. Ключи доверия и шифрования задаются вне проекта. Поддерживаемый базовый профиль — single-node, local storage, файловая/PostgreSQL очередь. Не запускайте обновление, пока не проверено восстановление на копии вашей установки.

## Перенос сайта

ZIP export v3 переносит контент, медиа, CMS-конфигурацию и разрешённые настройки. Перед импортом оператор проверяет diff и подтверждает digest плана; применение использует полный backup с автоматическим восстановлением. [Руководство Import/Export](docs/import-export.md).

Подробнее: [Production updater & backup](docs/update-backup.md), [Установка](docs/install.md), [Security](SECURITY.md), [Changelog](CHANGELOG.md).

## Расширения

Поддерживает подписанные модули, плагины и темы, зависимости, расширяемые административные действия, очередь, scheduler и безопасный upgrade с резервным копированием. [Руководство Extension Platform](docs/extensions-platform.md); рабочие примеры находятся в `examples/extensions/`.
### SEO & URL Migration (1.4.7)

After content and media migration, run `php bin/cajeer migration:seo-import PROJECT_ID --yes --origin=https://old.example.com --legacy-map=/srv/legacy-urls.json`. Only confirmed old URLs are redirected; see `docs/seo-url-migration.md`.

### Media & Attachments Migration (1.4.6)

After the approved DLE/WordPress content import, run `php bin/cajeer migration:media-import PROJECT_ID /srv/source/uploads --yes --origin=https://old.example.com` from the server operator shell. This copies validated files through the Media Library, reuses SHA-256 duplicates, rewrites the project's content links, and provides resumable SQL receipts. WordPress featured images can be mapped with `--featured-field=cover` when the target type contains a `media` field. See [Media Migration](docs/media-attachments-migration.md) and the supported file type/rollback limitations.

### Users & Identity Migration (1.4.5)

DLE and WordPress imports create disabled accounts, link authors and support explicit viewer/editor roles. See [Identity Migration](docs/identity-migration.md).

### WordPress Migration (1.4.4)

Фактический перенос записей, страниц и пользовательских типов из WXR, MySQL/MariaDB и REST (posts/pages), с проверкой утверждённого плана, транзакцией и журналом результата. [Документация](docs/wordpress-migration.md).

### DLE Migration (1.4.3)

Выполнение подтверждённого DLE-плана с переносом новостей и статических страниц, исторических дат, привязкой существующих авторов и SQL-журналом результата. Подробнее: [DLE Migration](docs/dle-migration.md).

### Mapping & Migration Planning (1.4.2)

- [Мастер сопоставления и Dry Run](docs/migration-planning.md): чтение фактических записей, типизированные преобразования, конфликты slug и пользователей, сохранение и подтверждение плана с повторной проверкой источника и назначения.
- На этом этапе целевой контент не изменяется. Ограничения и поддерживаемые источники описаны в документации.

### Migration Center & Discovery (1.4.1)

Read-only discovery of DLE and WordPress sources, WXR exports, secure projects and compatibility reports in `/admin/#/migration`. See [Migration Center](docs/migration-center.md).

### Analytics Hub GA (1.4.0)

Production analytics with previous-period comparisons, durable sync receipts and bounded history, source health and migration `0017`. See [GA operations](docs/analytics-ga.md).

### Advertising & Conversion Tracking (1.3.9)

Consent-gated Google Ads, Meta Pixel, TikTok Pixel, VK Ads and Yandex Direct goals, direct Meta/TikTok server conversion delivery, UTM attribution and diagnostics. [Setup and security](docs/advertising-conversions.md).

### Infrastructure Analytics (1.3.8)

Cloudflare Edge Analytics and Web Analytics / RUM: consent-gated manual beacon, GraphQL reports, cache/status/security and Core Web Vitals. See [setup](docs/infrastructure-analytics.md).

### Alternative Analytics (1.3.7)

Matomo and Plausible tracking, consent-aware event routing and API reports: [configuration](docs/alternative-analytics.md).

### Search & Webmaster (1.3.6)

Analytics Hub supports Yandex Webmaster and Google Search Console: search performance, queries, pages, sitemap diagnostics and submission, and Google URL inspection. See [configuration and permissions](docs/search-webmaster.md).

### Analytics Hub (1.3.5)

Read-only Metrika and GA4 reports, SQL snapshots, encrypted credentials, queue synchronization and admin CSV export. See [Analytics Hub setup](docs/analytics-hub.md).

### Analytics Events (1.3.4)

Consent-gated event routing, configurable provider mappings, automatic search/download/outbound/scroll events and optional first-party diagnostics. See [Analytics Events](docs/analytics-events.md).

### Tracking & Counters (1.3.3)

Manage Yandex Metrica, GA4 and GTM at `/admin/#/tracking`. Real vendor tags are consent-gated and restricted to explicit public hostnames; GA4 page views and Metrica goals use `CajeerTracking`. See [Tracking & Counters](docs/tracking-counters.md).

### Consent Management (1.3.2)

Manage privacy policy at `/admin/#/privacy`. Public pages display a consent banner and only activate opt-in script descriptors after consent. See [Consent Management](docs/consent-management.md).

### Integration Center (1.3.1)

См. [руководство по интеграциям](docs/integration-center.md).

### AI Background Automation (1.7.8)

Owner-scoped scheduled and manual OpenAI prompts run on the existing Scheduler and QueueWorker, with saved conversations, pause/resume, history and AI budget enforcement. [Configuration and operations](docs/ai-background-automation-1.7.8.md).

### AI Automation Security & Acceptance (1.7.9)

The background automation delivery loop now serializes per-owner quotas, reconciles dead-letter jobs without repeating a provider call, fairly re-offers queued work, rejects inactive owners and retains only 30 days of completed prompt snapshots. Apply migration `0042_ai_automation_delivery_acceptance.sql` and review [security and recovery procedures](docs/ai-automation-security-acceptance-1.7.9.md).
