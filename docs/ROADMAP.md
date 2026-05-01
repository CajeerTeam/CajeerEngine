# Roadmap Cajeer Engine

## v1.0 — Core CMS

Цель: базовая самостоятельная CMS.

- PHP core.
- Nginx routing.
- PostgreSQL/MySQL drivers.
- Installer.
- Admin panel.
- Content model.
- Users/roles.
- Media library.
- Native themes.
- Native plugins/modules.
- Basic security layer.
- Logging.

## v1.1 — WordPress Import

Цель: импорт WordPress-сайтов в Cajeer Engine.

- `wp_posts`.
- `wp_postmeta`.
- users.
- terms/taxonomies.
- media.
- comments.
- menus.
- basic SEO metadata.
- custom post types.
- dry-run import mode.
- import report.

## v1.2 — DLE Import

Цель: импорт DLE-сайтов в Cajeer Engine.

- news.
- categories.
- users.
- comments.
- xfields.
- static pages.
- tags.
- uploaded files.
- dry-run import mode.
- import report.

## v1.3 — DLE Template Compatibility

Цель: запуск базовых DLE `.tpl` шаблонов.

- `.tpl` parser/compiler.
- `main.tpl`.
- `shortstory.tpl`.
- `fullstory.tpl`.
- `comments.tpl`.
- template tags.
- condition tags.
- include tags.
- compiled template cache.

## v1.4 — WordPress Classic Theme Compatibility

Цель: частичная поддержка WordPress classic PHP themes.

- `index.php`.
- `single.php`.
- `page.php`.
- `archive.php`.
- `header.php`.
- `footer.php`.
- `sidebar.php`.
- `functions.php`.
- basic WP template functions.
- `wp_head`.
- `wp_footer`.
- template hierarchy subset.

## v1.5 — WordPress Plugin Compatibility Layer

Цель: частичный запуск простых WordPress-плагинов.

- hooks API.
- filters API.
- options API.
- shortcode API.
- basic posts API.
- basic users API.
- compatibility scanner.
- unsupported function report.

## v1.6 — DLE Module Compatibility Layer

Цель: частичный запуск DLE-модулей.

- `$db` adapter.
- `$tpl` adapter.
- `$config` adapter.
- `$member_id` mapping.
- legacy module runner.
- module scanner.
- safe mode.
- compatibility logs.

## v1.7 — Marketplace

Цель: каталог расширений Cajeer Engine.

- marketplace themes.
- marketplace plugins.
- marketplace modules.
- compatibility badges.
- upload flow.
- user profile integration.
- moderation queue.
- extension metadata validation.

## v1.8 — API and Headless Mode

Цель: headless CMS API.

- public content API.
- admin API.
- auth tokens.
- rate limiting.
- OpenAPI schema.
- API permissions.

## v1.9 — Stability and Observability

Цель: подготовка к production/LTS.

- structured logs.
- health endpoint.
- diagnostics page.
- cache diagnostics.
- DB diagnostics.
- template diagnostics.
- extension diagnostics.
- backup/export hooks.

## v2.0 — Plugin-first Platform

Цель: стабильный Kernel API и platform-ready архитектура.

- stable extension lifecycle.
- stable kernel contracts.
- marketplace signing.
- compatibility certification.
- upgrade policies.
- LTS branch policy.
