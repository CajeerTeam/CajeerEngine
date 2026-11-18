# Changelog

## 1.5.7 — Identity Center Admin UX

- Working Identity Center in shipped static admin SPA and Nuxt UI: search/page/filter local identity accounts, inspect SSO sessions, link a verified Keycloak `sub`, unlink with safe local-login checks, revoke selected or all SSO sessions.
- Server-side HTTPS browser-session authorization, administrator RBAC, CSRF and recent password/TOTP or OIDC MFA step-up for all mutations; audit subject digests and target IDs, deny self-operator changes, never merge by email.
- Transactional unlink plus local OIDC session revocation, without revoking local logins, service tokens or changing Keycloak accounts. No Keycloak Admin API credentials or browser secrets.
- Regression coverage for PDO, HTTP authorization, and browser interactions; OpenAPI operations and extension SDK synchronized.


## 1.5.5 — SkiF4er ID OIDC API Authentication

- Validate real Keycloak OAuth access tokens using the existing pinned JWKS/RS256 client, with strict access-token type, issuer, API audience, OAuth client, lifetime and scope checks. ID tokens and Logout Tokens never authorize the API.
- Opt-in live token introspection detects revoked/disabled access tokens; provider outages deny external authentication without affecting existing local logins.
- Resolve only explicitly linked `(issuer, sub)` local accounts and enforce active status plus the intersection of current local RBAC with the configured API permission ceiling.
- Preserve `ce_` API tokens and browser sessions; prevent external JWTs from operating browser-session, identity-linking or API-token management endpoints.
- Add deterministic signed-token protocol regression and mandatory real-PDO AuthService/HTTP middleware regression to release CI. No database migration.

## 1.5.4 — SkiF4er ID SSO & Global Logout

- RP-Initiated Logout initiated by an authenticated, CSRF-protected browser POST; immediately revoke the local OIDC session and redirect through the pinned Keycloak end-session endpoint.
- Keycloak Back-Channel Logout: verify signed RS256 JWT using JWKS, audience, issuer, timestamps, event, `jti`, `sid` and/or `sub`; reject nonce and malformed events.
- Atomic SQL replay detection and targeted revocation of OIDC browser sessions only, preserving local password sessions and unrelated API tokens.
- Track hashed OIDC subject for precise revocation, retain sid provenance on session rotation, and support sessions created before 1.5.4.
- Admin SPA / Nuxt global logout control, release CI regressions, documentation and migration `0029_oidc_global_logout.sql`.

## 1.5.3 — Unified Session Management

- Local and SkiF4er OIDC sessions now have a durable per-device SQL registry. Existing 1.5.2 `admin-session` credentials are backfilled on first valid use.
- Session management in both admin frontends: current device, timestamps, masked network, logout one, others or all, and cookie rotation with new CSRF.
- Browser-authenticated session endpoints reject bearer-only API tokens and require CSRF for modifications. Token revocation is atomic with session state.
- Configurable 12-hour idle timeout, absolute expiry, no access/refresh tokens in browser-facing reports, and unchanged external IdP session/logout semantics.
- Migration `0028_unified_sessions.sql` for SQLite/PostgreSQL/MySQL; tests and release assets updated.

## 1.5.2 — SkiF4er ID Account Linking & Provisioning

- Self-service OIDC account linking: local session, password + optional 2FA reauthentication, short-lived PKCE redirect and atomic `(issuer, sub)` link. Callback refuses expired or revoked initiating sessions.
- Self-service unlink requires local reauthentication; OIDC-only provisioned users cannot remove their sole login method.
- Opt-in new-user provisioning requires `email_verified: true`, an unused email and verified safe viewer permissions. Existing emails are never merged automatically. New local password is random and undisclosed.
- Identity status and forms in shipped production Admin SPA and Nuxt source; SQL migration 0027 for SQLite, PostgreSQL and MySQL, CI and real SQLite checks.
- Does not implement global SSO logout, per-device session management, public password reset or a full live Keycloak acceptance test.

## 1.5.1 — SkiF4er ID Authentication Core

- Working confidential-client OIDC Authorization Code + PKCE S256 against the SkiF4er ID issuer, with HTTPS pinned discovery, token exchange, JWKS and RS256 claim validation.
- SQL one-time browser-bound authorization transactions, authenticated encryption of PKCE verifier, explicit operator-only `(issuer, sub)` linking, local RBAC session creation.
- Login button in production static Admin SPA and Nuxt source. Feature off by default; local login remains available. No email-based implicit account linking.
- Migration `0026_skif4er_oidc.sql` across SQLite, PostgreSQL and MySQL. OpenAPI/SDK sync; signed JWT regression and SQLite schema regression in release CI.
- Self-service linking, provisioning, unified sessions and global logout are **not** implemented in 1.5.1.

## 1.5.0 — Migration Center DLE + WordPress GA

- Validate every source-to-target content receipt against the approved plan, rejecting missing/duplicate/altered receipts.
- Audit latest target revision snapshots and provenance of every later edit; detect undetected editorial drift, not just slug/status drift.
- Label revisions written by content migration, media rewriting and SEO rewriting, permitting safe post-media/SEO incremental sync.
- Accept one legacy 1.4.x media revision only when backed by the stored SHA-256 receipt. Unlabelled legacy SEO/editor modifications require manual reconciliation.
- Persist project-owned legacy redirect evidence, validate actual 301 status/destination and individual SEO metadata before cutover (SQL migration 0025).
- Detect incomplete media rewrite receipts as cutover blockers; extend local release and CI regressions.
- No changes to DNS, remote CMS databases or untrusted PHP/plugin execution. Production acceptance still requires a full release CI and real-site rehearsal.

## 1.5.0 — Migration Validation & Cutover

- CLI source/target validation with real content, route, sitemap, SEO and SHA-256 media checks.
- Reviewed incremental synchronization with batch checkpoint, pre-sync verified backup and full restore.
- Operator-verified local site activation and cutover receipt; DNS remains infrastructure-managed.
- See `docs/migration-validation-cutover.md`.

## 1.4.8 — Migration Execution & Recovery

- Real operator-only batch importer for approved DLE/WordPress plans, with SQL checkpoints, idempotent resumes and complete receipts.
- Verified pre-execution backup, site maintenance, controlled full backup rollback, and crash recovery.
- Raised bounded source inventories to 5,000 content items / users and 64 MiB. WXR up to 32 MiB.
- Added operator CLI commands, progress in Migration Center, SQL migrations and regressions.


## 1.4.7 — SEO & URL Migration

- Operator-only `migration:seo-import` after DLE/WordPress content import; source records and approved receipt mapped to current public routes.
- Read-only SEO metadata overlay from DLE and supported WordPress SEO plugin fields, respecting editor overrides and public indexing rules.
- Atomic verified legacy 301 redirects (and WordPress plain query permalinks), internal-link rewrites with revision conflict protection, and SQL execution receipt.
- `0022_seo_url_migration.sql` for SQLite, PostgreSQL, MySQL; SEO stage report in Migration Center and test suite.

## 1.4.6 — Media & Attachments Migration
- Import local DLE / WordPress uploads into the real MediaRepository with MIME, size and SHA-256 verification; reuse existing physical assets on repeat runs.
- Rewrite upload URLs inside only committed migration entries using revision checks and crash-recoverable intent receipts; report missing and unsupported assets explicitly.
- Optional WordPress featured-media binding through WXR, read-only database or REST attachment metadata, without exposing source credentials.
- Operator CLI, read-only Migration Center progress, three SQL migration 0021 variants, media and SQLite regression tests. Full-tree rollback requires a pre-migration backup.

## 1.4.5 — Users & Identity Migration

- Transactional source user creation, author links, safe roles and one-time operator activation.
- Durable identity receipts and blocked source account protection.

## 1.4.4 — WordPress Migration

- Execute approved WordPress WXR, database and REST content plans through the shared transactional importer.
- Preserve mapped metadata, authors, UTC publication dates and source IDs; password-protected content stays non-public and richtext is sanitized.
- Add operator CLI, immutable execution receipts, replay protection, migration 0019 and production wizard result views. Existing DLE execution remains compatible.

## 1.4.3 — DLE Migration

- Реальное выполнение подтверждённого DLE-плана через штатный репозиторий контента, с транзакционным обновлением маршрутов, поиска и SQL-квитанции.
- Перенос новостей, статических страниц, xfields, категорий/тегов через mapping, исторических дат и явно сопоставленных авторов; безопасный HTML и простые BBCode.
- Операторская команда `migration:dle-import`, повторный запуск без дублирования, итоговый JSON в мастере, миграция 0018.

## 1.4.2 — Mapping & Migration Planning

- Real bounded DLE/WordPress DB, REST and WXR content inventory, persistent mapping rules and typed field transformations.
- Read-only target validation, locale-aware slug conflict resolution, explicit user links/new viewer plans, and plan hashes checked again on approval.
- Production admin mapping editor, dry-run report and JSON export; no target writes or SQL migration.

## 1.4.1 — Migration Center & Discovery

- Persistent migration projects and a production admin wizard for DLE MySQL/MariaDB, WordPress database, REST and WXR.
- Read-only source inspection with exact database counts, REST totals, WXR parsing, field/type/status inventories, and explicit compatibility limits.
- Encrypted credentials and WXR archives, operator endpoint allowlists, revision-guarded scans, last successful report retention, audit and RBAC.
- No target content or database changes; no new SQL migration.

## 1.4.0 — Analytics & Tracking / Analytics Hub GA

- Equal-length previous-period comparisons of saved provider totals, with explicit missing/incomplete data and sampling diagnostics.
- Durable per-job synchronization receipts, retry history, lease fencing, isolated scheduler failures, report-size limits and operational overview.
- Migration 0017 for SQLite, PostgreSQL and MySQL; existing sources, reports, permissions and credentials preserved.
- GA regression checks for comparison, queue replay, interrupted sync, upgrade and backup/restore.

## 1.3.9 — Advertising & Conversion Tracking

- Google Ads, Meta Pixel, TikTok Pixel, VK Ads/Top Mail.Ru and Yandex Direct goals with analytics + marketing consent.
- Explicit conversion rules, Google label mapping, per-event diagnostics, session-scoped UTM attribution and shared GA4 Google tag.
- Real Meta Conversions API and TikTok Events API delivery through the existing queue with encrypted matching data, short marketing consent leases and stable event identifiers.
- New configuration and API schemas remain backward-compatible; no SQL migration required.
- Added PHP and Chromium advertising regression checks to release CI.

## 1.3.8 — Infrastructure Analytics

- Cloudflare Edge and Web Analytics reporting with fixed GraphQL queries, encrypted credentials, SQL snapshots and queue synchronization.
- Consent-gated manual Web Analytics beacon, CSP, cache and HTTP status summaries, optional security and performance metrics, RUM Core Web Vitals.
- Updated admin, API schema and regression coverage.

## 1.3.7 — Alternative Analytics

- Matomo and Plausible: consent-aware browser events, real API reports, operator-approved endpoints and admin configuration.
- Existing report storage, queue, scheduler and encrypted credentials reused; no SQL migration.

## 1.3.6 — Search & Webmaster

- Yandex Webmaster and Google Search Console real search performance reports, current sitemap and indexing diagnostics, sitemap submission and Google URL inspection.
- Shared Analytics Hub credentials, SQL snapshots and queue; provider-specific site identifiers, migration 0016, admin workflows and synchronized SDK.

## 1.3.5 — Analytics Hub Core

- Real Metrika Reporting API and GA4 Data API requests, encrypted credentials, SQL snapshots, hourly queue synchronization and admin report/CSV workflows.
- Added migration 0015 for SQLite, PostgreSQL and MySQL; read/write/run analytics scopes.

## 1.3.4 — Analytics Events

- Consent-aware event routing, provider mappings, automatic CMS search/download/outbound/scroll tracking, browser journal and optional first-party diagnostics.
- Fix tracking controller registration in the application router.

## 1.3.3 — Tracking & Counters

- Consent-gated Yandex Metrica, GA4 and exclusive GTM runtime, explicit host scope and page views.
- Durable revision-controlled counter configuration, audit, RBAC, production admin and synchronized SDK.
- Browser event/goal calls, provider load diagnostics, revocation and static export support.

## 1.3.2 — Consent Management

- Versioned privacy policy with scoped administration, audit and optimistic locking.
- Public consent preferences, granular categories, expiry, revocation, local history and Google Consent Mode v2 defaults.
- Consent-gated optional script loading with public CSP allowlist, render-cache isolation and static export support.

## 1.3.1 — Integration Center

- Реальный runtime провайдеров HTTP JSON и HMAC webhook, управление соединениями и тестовые отправки.
- Зашифрованные credentials, отдельные RBAC scopes, аудит и optimistic concurrency.
- Production Admin UI, OpenAPI и SDK синхронизированы.

## 1.3.0 — Integration Certification / Production Stable

- Real TLS production HTTP security, CMS lifecycle and concurrent mixed workload acceptance with per-route latency and throughput budgets.
- Dependency audit and artifact-bound acceptance evidence required before signing and GitHub publication.
- Hardened request throttling and static administration security headers.
- Production admission is conditional on passing the complete release CI; local stable status is not certification.

## 1.2.5 — GitHub distribution migration

- GitHub Releases REST discovery, canonical release assets and signed draft-first publication after release verification.
- Distribution URLs and contributor instructions moved to GitHub; existing updater signature trust is unchanged.

## 1.2.5 — Production Deployment

- Signed, SHA-256 pinned production deployment from a clean Composer-locked dist, without dependency resolution on the target.
- Dedicated Nginx TLS site, PHP-FPM socket, queue worker and persistent systemd scheduler timer. Runtime services mount application code read-only.
- Root-owned deployment journal and external maintenance gate, explicit recovery resume, preflight, HTTP readiness and transactional update rollback.
- Real Nginx/TLS safety regression and full PostgreSQL/systemd deployment smoke gate in release CI.

## 1.2.4 — Disaster Recovery

- Streaming authenticated encryption for off-host database, files, and environment recovery sets.
- Atomic import with full verification before publishing; no overwrite of existing recovery sets.
- Isolated SQLite and PostgreSQL restore drills with data/table checks, recovery-time and snapshot-age budgets.
- Real cold recovery release smoke: destroy installation, recover from external bundle, boot and verify content.

## 1.2.3 — Observability & Operations

- Independent liveness/readiness, protected Prometheus endpoint and operations API/CLI.
- Cross-process HTTP, database, job and scheduler histograms; queue age, backup and worker timestamps.
- Bounded rotating runtime JSON logs and W3C trace correlation.
- Real admin operational status dashboard and alert rules.

## 1.2.2 — Queue & Scheduler Reliability

- Durable queue reservations with fencing tokens, heartbeat, expiry recovery and delayed exponential retry.
- Atomic file and PostgreSQL transitions, Redis Lua transitions and offline legacy migration.
- Persistent UTC scheduler slots, file or PostgreSQL advisory locks, interrupted run history.
- Durable webhook outbox handoff, per-target jobs, stable signed event IDs and delivery receipts.
- Bounded CLI workers, graceful signals, operational recovery/diagnostics and corrected systemd units.

## 1.2.1 — Performance & Database

- Bounded editorial SQL pagination with deterministic sorting and indexed translation lookup.
- Incremental route/search projection for topology-neutral edits; full graph validation for structural edits.
- Database-side revision compare-and-swap, nested savepoints, session timeouts and SQLite foreign keys.
- Serialized schema migrations; MySQL interrupted DDL is not retried automatically.
- Production auto storage fails closed instead of switching to JSON. Admin uses paginated lists and parent search.

## 1.2.0 — Search & CMS Integration

- Search projection is synchronized with content and route changes, with PostgreSQL FTS and SQLite/MySQL or file catalog.
- Public search page and API, filtered pagination, relevance, facets, safe result rendering, real admin search.
- Rebuild replaces stale documents; import, backup, updater and scheduler use the same catalog.

## 1.1.9 — Admin & Public Rendering Completion

- Schema-driven entry and field editor, reorderable blocks, media picker, revision restore and localization workflows.
- Built-in theme selection, corrected structured block rendering, referenced media lookup, preview toolbar and optional validated HTML cache.
- Revision preconditions and staged static site export preserving the real homepage.


## 1.1.8 — Media Library

- SQL-backed metadata with explicit legacy migration; folders, tags, collections, filtered pagination.
- Streamed uploads, safe replacement, image variants, reference checks, private downloads and signed S3 replicas.
- Portable import/export and Admin SPA media workflows; recovery and verification CLI.

## 1.1.7 — Public Routing Runtime (2026-11-12)

- Transactional SQL route index and deterministic page trees; canonical paths, locale prefixes, redirect history, and published-ancestor visibility.
- Protected route inspection API, CMS editor tree and parent selection, canonical SEO, sitemap pagination and hreflang.
- Explicit 404/410 pages, redirect safety, and import/upgrade integration.

## 1.1.6 — Extension Platform (2026-11-12)

- Signed extension inventory, dependency ordering, guarded package deployment and backup recovery.
- Runtime actions, admin pages, jobs, scheduler tasks, and active theme rendering.

## 1.1.5 — Import/Export Engine (2026-11-12)

- Portable ZIP v3 exports with checksummed media files and bounded extraction; checksummed JSON v2 remains supported.
- Preview and confirm an import plan with locale-aware identity mapping, conflict policies, CMS repositories, history and backup recovery.
- Admin upload/download and operator-executed import requests.


## 1.1.4 — Production Updater & Backup (2026-11-12)

- Ed25519 release signatures, verified staging, exclusive operation lease, durable update journal, automatic recovery and CLI rollback.
- Authenticated recovery sets with database dumps, full managed code/data inventory and encrypted environment; exact restore and integrity validation.
- Protected web upgrade requests executed by an explicitly selected CLI operator; no web code replacement.
- Database and failure-injection regression scenarios; external storage/queues require separately validated recovery.

## 1.1.3 — OpenAPI & Security (2026-11-12)

- Single route registry and strict OpenAPI 3.1.1 parity for 119 core operations; runtime JSON input validation.
- Correct middleware interface, mandatory authorization and current account/role restrictions; browser HttpOnly session with CSRF.
- Web setup token, MIME validation, SSRF-resistant outbound HTTP, safe public rendering.
- Production apply/rollback and destructive import suspended pending safe recovery and transactional import.
- API/SDK regression checks and HTTP integration smoke cases.

## [1.1.2] - 2026-11-12

- Clean source/dist packaging: allowlisted files, deterministic ZIP output, checksums and verified archive inventory.
- Production Admin SPA published from a single source; read-only asset checks and no fallback repair.
- Strict release verification and clean Git staging build with locked Composer dependencies.
- Installation lock acquired before work and completion marker written only after final checks.
- Regression tests and SQLite/PostgreSQL dist installation smoke pipeline.

## [1.1.1] - 2026-06-19

### Added
- Production static Admin SPA assets in `public/admin/assets/admin-app.js` and `admin-app.css`, backed by `admin/static` source files.
- Bearer login/logout, token storage, route guards and 401/403 handling in the browser Admin UI.
- Working Admin screens for dashboard, content types, content entries, media upload, CMS theme/navigation/redirects, users/RBAC/API tokens, extensions runtime, updates/maintenance/rollback, search and import/export.
- Strict Admin asset manifest with production quality checks for JS/CSS size and entrypoints.

### Changed
- `admin/scripts/publish-admin-assets.mjs` now publishes generated Nuxt assets when present or production static assets from `admin/static`, and fails `--check` when assets are incomplete.
- `php bin/cajeer admin:assets --check` and `AdminAssetPublisher` now validate real production Admin assets instead of accepting tiny preview fallback files.
- `fix:admin-assets` restores Admin assets from `admin/static` instead of writing a minimal placeholder page.
- Admin bootstrap metadata now exposes all product sections: CMS, media, updates, search, import/export and extension runtime.

### Fixed
- Source archives now include the same production Admin UI source used to populate `public/admin`, so dist assets can be regenerated deterministically.

## [1.1.0] - 2026-06-19

### Added
- Import/export v2: full content import for content types and entries, media file embedding/restoration, safe users/roles import and import diff reports.
- Update hardening: code rollback snapshots, removed files support, post-update migrations, post-update doctor and maintenance mode during apply.
- CLI/API commands for `import:diff`, `update:rollback` and maintenance mode control.
- Admin SPA operations for Import/Export, Updates, Maintenance and Rollback.

### Changed
- Web Upgrade now exposes rollback snapshot handling and maintenance status.
- Public entrypoint returns HTTP 503 while `storage/app/maintenance.json` exists.
- Release/update metadata moved to 1.1.0.

### Security
- Downgrade is blocked by default unless `--force` is explicitly used.
- Update apply protects runtime data and creates a code-level rollback snapshot before copying artifact files.

## [1.0.8] - 2026-06-19

### Added
- CMS product layer: public path rendering, homepage resolution, page path support and default `pages` content type bootstrap.
- Database-backed CMS repositories with file fallback: theme config, navigation menus, redirects and preview tokens.
- Public `/sitemap.xml`, `/robots.txt` and `/preview/{token}` routes.
- Admin UI CMS screen for theme settings, primary navigation and redirects.
- CLI commands `cms:bootstrap` and `cms:sitemap`.

### Changed
- Public renderer now passes SEO, canonical URL, theme and navigation context into templates.
- Default templates now render site navigation, meta description, robots, canonical and OpenGraph tags.
- Static export uses public CMS paths instead of raw slugs.

### Security
- Draft preview requires generated time-limited preview tokens for public `/preview/{token}` URLs.


Все заметные изменения CajeerEngine фиксируются в этом файле. Формат совместим с Keep a Changelog, версия проекта следует SemVer.

## [1.0.7] - 2026-06-19

### Added
- Product Admin UI hardening: static SPA in `public/admin` with login, token storage, route guards, dashboard, content type CRUD, content entry CRUD, media upload, users/roles, API tokens, extensions, system and updates screens.
- Protected Web Upgrade session gate: `/upgrade` now requires `UPDATE_WEB_UPGRADE_ENABLED=true` and `UPDATE_WEB_UPGRADE_SECRET` before ZIP upload/apply actions.
- Admin asset manifest now tracks `admin-app.js` / `admin-app.css` as production static assets while keeping legacy preview aliases.

### Changed
- Release builder excludes patch manifests and update runtime artifacts from source/dist release plans.
- OpenAPI metadata and product release metadata updated for 1.0.7 Admin UI hardening.
- Dist requirements now validate the production Admin app asset instead of the old preview script.

### Security
- Browser-based update apply is disabled by default and requires an explicit one-session unlock secret. CLI update remains the recommended production path.

## [1.0.6] - 2026-06-18

### Добавлено
- Release UX: `release:build --source/--dist/--all`, `release:artifacts`, `release:checksums` с ZIP artifacts, `.sha256` файлами и GitFlic-ready artifact index.
- `core/Update/UpdateManager.php` как рабочий update backend: check/plan/prepare/apply, проверка manifest/SHA256, protected-path filtering.
- CLI updater: `php bin/cajeer update`, `update:prepare`, `update:apply` с обязательным backup-before-update по умолчанию.
- Web Upgrade Wizard `/upgrade` и thin-loader `upgrade.php`: загрузка ZIP artifact, dry-run apply, сохранение plan и безопасное применение обновления.
- Release manifest v3 внутри artifacts с install/upgrade инструкциями, списком файлов, checksums и GitFlic metadata.

### Изменено
- `ReleaseBuilder` разделён на полноценные source/dist режимы и больше не ограничивается dry-run планом.
- Native CLI fallback теперь поддерживает release/update команды без `vendor/autoload.php`.
- Stable checks, OpenAPI/Admin metadata, bundled update metadata и docs обновлены до 1.0.6.

### Безопасность
- Update apply не перезаписывает `.env`, `storage/app/installed.lock`, `public/uploads`, SQLite database и runtime update/backup/support directories.
- Перед реальным применением update создаётся backup runtime-данных, если явно не указан `--no-backup`.

## [1.0.5] - 2026-06-18

### Добавлено
- Server automation layer: `doctor`, `fix`, `fix:permissions`, `fix:env`, `fix:security`, `fix:admin-assets`, `fix:storage`.
- Nginx config generator с default и aaPanel presets.
- systemd scheduler/worker generators.
- `post-install:report` и alias `backup:create`.

## [1.0.4] - 2026-06-18

### Добавлено
- Реальный Web Installer на `/install` и `public/install/index.php`: проверка окружения, выбор БД, генерация `.env`, проверка подключения, запуск миграций, создание первого администратора, генерация секретов и создание `storage/app/installed.lock`.
- CLI installer `php bin/cajeer install` с режимами `--interactive`, `--sqlite`, `--preset=sqlite`, `--preset=postgres`, `--preset=production`, `--preset=aapanel`.
- SQLite quick start через `storage/database/cajeer.sqlite` для demo/local/small installations.
- Native CLI fallback для `install` и `install:check`, чтобы установка могла стартовать даже без Symfony Console.
- Root `install.php` как безопасный thin-loader для простых хостингов, если проект ещё не установлен.

### Изменено
- `InstallerService` стал общим product backend для Web Installer и CLI Installer.
- Добавлена поддержка SQLite в DB connection, schema health checks, миграциях и базовых репозиториях seed/admin creation.
- ReleaseBuilder готовит имена `cajeerengine-1.0.4-source.zip` и `cajeerengine-1.0.4-dist.zip`; dist-режим исключает dev-only директории.

## [1.0.3] - 2026-06-18

### Исправлено
- Удалено поле `version` из корневого `composer.json`, чтобы `composer validate --strict` проходил без предупреждения Composer.
- Минимальная Composer platform PHP-версия поднята до `8.4.1`, что соответствует требованиям Symfony 8.1.
- Admin UI metadata обновлены до `1.0.3`; TypeScript закреплён на существующей стабильной версии `5.9.3`; добавлено требование Node `>=20.19.0`.
- Обновлены runtime fallbacks, release checks, OpenAPI metadata, SDK metadata и bundled update metadata до `1.0.3`.
- Удалены устаревшие активные маркеры старых 0.x-версий из README, `.env.example`, Admin metadata и runtime fallbacks.

### Эксплуатация
- Для production/dist-релиза по-прежнему требуются настоящие `composer.lock`, `vendor/autoload.php` и lock-файл Admin UI, создаваемые на release host.
- PHP extension `fileinfo` отмечен как рекомендуемый для строгой MIME-проверки media uploads.

## [1.0.2] - 2026-06-18

### Исправлено
- Добавлены prebuilt Admin assets и проверка их готовности.
- Добавлен native fallback для эксплуатационных CLI-команд без `vendor/autoload.php`.
- Исправлен retry/repair failed migrations.
- Добавлены `security:harden`, bundled update metadata и dist release checks.
- Удалены демонстрационные manifest-only расширения и runtime-файлы из source-архива.

## [1.1.0] - Extension Runtime

### Added
- Runtime-загрузка включённых расширений из `modules/`, `plugins/`, `themes/`.
- `ExtensionProviderInterface` и `AbstractExtensionProvider` для product-level PHP providers.
- `ExtensionContext` с доступом к root path, manifest, config, container, events, logger и runtime paths.
- Lifecycle hooks `register()`, `boot()`, `install()`, `uninstall()`.
- Выполнение runtime events/hooks через `extension:event:dispatch` и kernel event dispatcher.
- Автозагрузка PHP-кода расширений из `src/` и optional `autoload.php`.
- Publishing assets расширений в `public/extensions/{slug}`.
- File/PDO migrations расширений с состоянием в `storage/app/extensions/migrations.json`.
- API endpoints `/extensions/runtime`, `/extensions/assets/publish`, `/extensions/migrate`.
- CLI команды `extension:runtime`, `extension:assets`, `extension:migrate`.
- `make:extension` теперь генерирует рабочее runtime-расширение с provider, assets и migration.

### Changed
- `extension:install` теперь может запускать lifecycle, migrations и assets publishing.
- `extension:uninstall` теперь может вызывать provider uninstall lifecycle и удалять опубликованные assets.
- Engine API обновлён до `1.1` при сохранении совместимости с `^1.0` manifest constraints.
