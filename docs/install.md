# Установка CajeerEngine 1.1.1

## Web Installer

Основной путь для обычного пользователя:

1. Скачать `cajeerengine-1.1.1-dist.zip`.
2. Загрузить архив на сервер.
3. Распаковать проект так, чтобы web root указывал на `public/`.
4. Открыть `https://site.ru/install`.
5. Выбрать SQLite, PostgreSQL или MySQL/MariaDB.
6. Заполнить данные первого администратора.
7. Нажать «Установить».

После успешной установки создаётся `storage/app/installed.lock`. Пока этот файл существует, `/install` возвращает запрет доступа.

## CLI Installer

Интерактивный режим:

```bash
php bin/cajeer install --interactive
```

Быстрый SQLite-режим:

```bash
php bin/cajeer install \
  --sqlite \
  --domain=site.ru \
  --admin-email=admin@site.ru \
  --admin-password='StrongPassword123'
```

Production PostgreSQL:

```bash
php bin/cajeer install \
  --preset=production \
  --db=pgsql \
  --db-host=127.0.0.1 \
  --db-port=5432 \
  --db-name=cajeerengine \
  --db-user=cajeerengine \
  --db-password='secret' \
  --domain=site.ru \
  --admin-email=admin@site.ru \
  --admin-password='StrongPassword123' \
  --production
```

## aaPanel preset

```bash
php bin/cajeer install --preset=aapanel --interactive
```

Ожидаемые частые пути aaPanel:

- PHP: `/www/server/php/84/bin/php`
- Web root: `/www/wwwroot/<domain>`
- Nginx vhost: `/www/server/panel/vhost/nginx/<domain>.conf`

## SQLite vs PostgreSQL

SQLite создаётся в `storage/database/cajeer.sqlite` и подходит для локального теста, демо и маленьких установок.

PostgreSQL 17+/18+ рекомендуется для production.

## Что делает installer

- создаёт runtime-директории;
- создаёт `.env` из `.env.example`;
- генерирует `APP_KEY`, `JWT_SECRET`, `API_TOKEN_SECRET`, `WEBHOOK_SIGNING_SECRET`;
- проверяет DB connection;
- запускает SQL-миграции;
- создаёт роли, базовые настройки и первого администратора;
- выполняет финальную проверку;
- создаёт `storage/app/installed.lock`.


## Admin UI 1.1.1

После установки откройте:

```text
https://site.ru/admin/
```

Войдите под первым администратором, созданным installer-ом. Static Admin SPA уже лежит в `public/admin/assets/` и не требует Node.js на production-сервере. В админке доступны:

- dashboard;
- типы контента с JSON field builder;
- записи контента с publish/unpublish/archive/revisions;
- media library upload/delete;
- CMS theme/navigation/redirects/preview controls;
- пользователи, роли и API tokens;
- extension runtime diagnostics, assets, migrations и event dispatch;
- updates, maintenance и rollback controls;
- search diagnostics/reindex;
- import/export export/diff/run;
- system/runtime/database/security diagnostics.

Проверка production assets:

```bash
php bin/cajeer admin:assets --check --json
node admin/scripts/publish-admin-assets.mjs --check
```

## Server automation 1.1.1

После установки или переноса на другой сервер проверь окружение одной командой:

```bash
php bin/cajeer doctor
php bin/cajeer doctor --json
```

`doctor` проверяет PHP/extensions, права runtime-директорий, `.env`, `installed.lock`, подключение к БД, миграции, Admin assets, security hardening, документацию, шаблоны, stable/update metadata, Nginx hints и systemd hints. Подробный отчёт сохраняется в `storage/app/reports/doctor-latest.json`.

Безопасные автоисправления по умолчанию работают в dry-run режиме:

```bash
php bin/cajeer fix
php bin/cajeer fix --yes
php bin/cajeer fix:permissions --yes
php bin/cajeer fix:env --yes
php bin/cajeer fix:security --yes
php bin/cajeer fix:admin-assets --yes
php bin/cajeer fix:storage --yes
```

Команды `fix` не удаляют данные, не меняют схему БД, не правят Nginx config и не перезапускают сервисы.

## Nginx config generator

Обычный Nginx:

```bash
php bin/cajeer nginx:config --domain=site.ru
```

aaPanel:

```bash
php bin/cajeer nginx:config --preset=aapanel --domain=site.ru
```

Записать конфиг в файл можно явно:

```bash
php bin/cajeer nginx:config --domain=site.ru --output=/etc/nginx/sites-available/site.ru.conf --write
```

Для aaPanel suggested path будет `/www/server/panel/vhost/nginx/<domain>.conf`.

## Systemd scheduler/worker

Сначала выведи unit-файлы без записи:

```bash
php bin/cajeer scheduler:install --print
php bin/cajeer worker:install --print
```

Безопасная запись в проект:

```bash
php bin/cajeer scheduler:install --write
php bin/cajeer worker:install --write
```

Запись в `/etc/systemd/system` требует прав root:

```bash
sudo php bin/cajeer scheduler:install --system --write
sudo php bin/cajeer worker:install --system --write
sudo systemctl daemon-reload
sudo systemctl enable --now cajeerengine-scheduler.timer
sudo systemctl enable --now cajeerengine-worker.service
```

## Post-install report и backup

```bash
php bin/cajeer post-install:report
php bin/cajeer backup:create
```

Post-install отчёт сохраняется в `storage/app/reports/post-install-latest.json`.

## Release UX 1.1.1

Сборка source artifact:

```bash
php bin/cajeer release:build --source
```

Сборка dist artifact:

```bash
php bin/cajeer release:build --dist
```

Сборка полного набора для GitFlic Release:

```bash
php bin/cajeer release:artifacts
```

Проверка checksum-файлов:

```bash
php bin/cajeer release:checksums
```

Release builder создаёт ZIP, `release-manifest.json` внутри архива, внешний `.sha256` файл и artifact index:

```text
storage/releases/cajeerengine-1.1.1-source.zip
storage/releases/cajeerengine-1.1.1-source.zip.sha256
storage/releases/cajeerengine-1.1.1-dist.zip
storage/releases/cajeerengine-1.1.1-dist.zip.sha256
storage/releases/cajeerengine-1.1.1-artifacts.json
```

Source artifact предназначен для разработчиков и не содержит `vendor/`. Dist artifact предназначен для установки на сервер и должен содержать `vendor/autoload.php`, `composer.lock` и готовые `public/admin/assets/`.

## Upgrade Wizard 1.1.1

Web-обновление доступно только после установки проекта, когда существует `storage/app/installed.lock`, и дополнительно требует явного включения:

```env
UPDATE_WEB_UPGRADE_ENABLED=true
UPDATE_WEB_UPGRADE_SECRET=replace-with-long-random-secret
```

После этого откройте:

```text
https://site.ru/upgrade
```

Без секрета страница показывает состояние, но не принимает ZIP и не выполняет apply. Wizard умеет:

- принимать ZIP artifact через upload;
- принимать локальный путь или URL artifact;
- сохранять update plan;
- делать dry-run apply;
- проверять `release-manifest.json`;
- сверять SHA256, если значение передано;
- создавать backup перед реальным apply;
- не перезаписывать `.env`, installed lock, uploads и SQLite database.

CLI-эквивалент:

```bash
php bin/cajeer update:check --json
php bin/cajeer update:prepare --package=/path/to/cajeerengine-1.1.1-dist.zip
php bin/cajeer update --package=/path/to/cajeerengine-1.1.1-dist.zip --dry-run
php bin/cajeer update --package=/path/to/cajeerengine-1.1.1-dist.zip --apply
```

По умолчанию перед `--apply` создаётся backup в `storage/app/backups`. Отключать это можно только явно:

```bash
php bin/cajeer update --package=/path/to/cajeerengine-1.1.1-dist.zip --apply --no-backup
```

## После установки: CMS bootstrap

Для готового публичного слоя CMS после миграций можно выполнить:

```bash
php bin/cajeer cms:bootstrap --demo-home
```

Команда создаёт тип контента `pages`, базовую главную страницу, theme config и меню `primary`. После этого доступны `/`, `/sitemap.xml`, `/robots.txt` и CMS-раздел в Admin UI.


## Import/export и update hardening 1.1.1

```bash
php bin/cajeer export:create --json
php bin/cajeer import:diff storage/app/exports/site.json --json
php bin/cajeer import:run storage/app/exports/site.json --json
php bin/cajeer update --package=/path/to/cajeerengine-1.1.1-dist.zip --dry-run --json
php bin/cajeer update --package=/path/to/cajeerengine-1.1.1-dist.zip --apply
php bin/cajeer update:rollback --backup=storage/app/updates/rollback/rollback-YYYYmmdd-HHMMSS-1.1.1.zip
```

При реальном `apply` CajeerEngine 1.1.1 по умолчанию делает runtime backup, создаёт code rollback snapshot, включает maintenance mode, копирует файлы artifact, обрабатывает `removed_files`, запускает migrations и выполняет post-update doctor. Downgrade заблокирован без `--force`.

