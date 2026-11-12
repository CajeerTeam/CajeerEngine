# Changelog

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
