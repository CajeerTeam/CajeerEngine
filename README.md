# Cajeer Engine

**Cajeer Engine** — PHP CMS и контент-платформа под **Nginx**, **PostgreSQL** и **MySQL/MariaDB** с архитектурой `plugin-first` и адаптационными слоями совместимости для **DLE** и **WordPress**.

Проект использует собственное ядро Cajeer Core. DLE/WordPress поддерживаются через compatibility adapters, а не как фундамент движка.

## Стек

```text
PHP 8.2+
Nginx / PHP-FPM
PostgreSQL 14+
MySQL 8+ / MariaDB 10.6+
Composer optional
```

## Структура

```text
app/                    прикладные сервисы и провайдеры
bin/cajeer              CLI
compatibility/          DLE и WordPress compatibility layer
configs/                конфигурация приложения
core/                   Kernel, HTTP, Routing, DBAL, Events, View, System
migrations/             SQL-миграции для mysql/pgsql
modules/                системные модули CMS
ops/nginx/              пример Nginx/aaPanel-конфига
plugins/                установленные плагины
public/                 front controller и публичные assets
resources/views/        PHP views
routes/                 web/admin/api/legacy маршруты
scripts/                служебные проверки и права
storage/                cache/logs/sessions/uploads/backups
wiki/                   исходники GitHub Wiki
```

## Быстрый локальный запуск

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Открыть:

```text
http://127.0.0.1:8080/install
http://127.0.0.1:8080/
http://127.0.0.1:8080/admin
http://127.0.0.1:8080/marketplace
http://127.0.0.1:8080/api/health
```

## Nginx / aaPanel

Использовать пример:

```text
ops/nginx/cajeer-engine.conf
```

Document root должен указывать на:

```text
/www/wwwroot/cajeer.ru/public
```

Права:

```bash
chown -R www:www storage public/uploads
chmod -R 775 storage public/uploads
```

Для Debian/Ubuntu без aaPanel пользователь часто `www-data:www-data`.

## CLI

```bash
php bin/cajeer health
php bin/cajeer doctor
php bin/cajeer install --database=mysql --admin-email=admin@example.test --admin-username=admin --admin-password='StrongPassword123!'
php bin/cajeer migrate --database=mysql
php bin/cajeer migrate:status --database=pgsql
php bin/cajeer cache:clear
php bin/cajeer dle:scan-template themes/default/main.tpl
php bin/cajeer wp:scan-plugin plugins/example/plugin.php
```

Пароль администратора не имеет небезопасного fallback. Его нужно передавать явно.

## GitHub Wiki

Подробная документация ведётся в `wiki/` и предназначена для публикации в GitHub Wiki.

## Совместимость

```text
L1: импорт данных
L2: шаблоны
L3: URL compatibility
L4: hooks/functions compatibility subset
L5: selected plugin/module compatibility
```

Полная бинарная совместимость со всеми плагинами DLE/WordPress не заявляется. Compatibility layer расширяется поэтапно.
