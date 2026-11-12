# CajeerEngine 1.1.4

API-first CMS на PHP с собственным ядром (`core/`), шаблонами `.cjr`, REST API (`api/openapi.yaml`) и статической админкой (`admin/static`, опубликованные файлы `public/admin`). CLI: `bin/cajeer`.

## Установка

- PHP 8.4.1+, mbstring, sodium, Phar, PDO и драйвер выбранной БД, openssl, fileinfo.
- PostgreSQL 17+ для эксплуатации; SQLite для локальной установки и небольших тестовых сайтов. MySQL/MariaDB — дополнительные драйверы, не сертифицированные этим релизом.
- Для полного dist не нужны Composer или Node на сервере. Распакуйте проверенный архив, укажите document root `public/`, настройте права на runtime и откройте `/install` либо запустите `php bin/cajeer install --interactive`.

```bash
php bin/cajeer install --sqlite --interactive
php bin/cajeer admin:assets --check
php bin/cajeer doctor
```

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
bash tools/release-smoke.sh /tmp/cajeer-release/cajeerengine-1.1.4-dist.zip sqlite
```

Сборка использует чистый Git snapshot и проверяет каждую запись ZIP, checksum и sidecar manifest. Dist включает `vendor/`, production Admin assets, миграции и пустые runtime-маркеры. Никакие данные рабочей установки, загруженные медиа, секреты и установленные пользователем плагины не включаются. Публиковать dist только после успешных smoke tests; SHA256 проверяет целостность, но не заменяет подпись доверенного издателя.

## Обновления и восстановление

В 1.1.4 подписанный dist применяется отдельным CLI-процессом, под maintenance и эксклюзивной блокировкой. Перед изменением файлов создаётся проверяемый набор восстановления БД и данных. Ключи доверия и шифрования задаются вне проекта. Поддерживаемый базовый профиль — single-node, local storage, файловая/PostgreSQL очередь. Не запускайте обновление, пока не проверено восстановление на копии вашей установки.

Подробнее: [Production updater & backup](docs/update-backup.md), [Установка](docs/install.md), [Security](SECURITY.md), [Changelog](CHANGELOG.md).
