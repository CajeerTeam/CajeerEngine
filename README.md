# Cajeer Engine

**Cajeer Engine** — PHP CMS и контент-платформа под **Nginx**, **PostgreSQL** и **MySQL/MariaDB** с архитектурой `plugin-first` и адаптационными слоями для совместимости с **DLE** и **WordPress**.

Цель проекта — не копировать WordPress или DLE, а предоставить собственное ядро Cajeer Core, поверх которого работают нативные модули, плагины, темы и compatibility adapters.

## Стек

```text
PHP 8.2+
Nginx
PHP-FPM
PostgreSQL 14+
MySQL 8+ / MariaDB 10.6+
Composer optional
```

## Что уже есть в этом архиве

```text
public/index.php              Front Controller
core/Kernel                   Загрузка приложения
core/Http                     Request / Response / Middleware
core/Routing                  Роутер
core/Events                   Event Dispatcher + Hooks
core/Database                 DBAL, драйверы PostgreSQL/MySQL, миграции
core/Extensions               Registry для модулей/плагинов/тем
core/View                     View renderer + заготовка template layer
modules/Content               Базовый модуль контента
modules/Admin                 Базовая админка
modules/Marketplace           Базовая витрина marketplace
compatibility/dle             DLE template tag parser + adapter
compatibility/wordpress       WP hooks/functions/shortcodes adapter
routes                        web/admin/api/legacy маршруты
config                        Конфигурация приложения
storage                       cache/logs/sessions/compiled_tpl/uploads
resources/views               Нативные PHP views
nginx                         Пример конфига Nginx
bin/cajeer                    CLI
```

## Быстрый запуск локально

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Открыть:

```text
http://127.0.0.1:8080
http://127.0.0.1:8080/admin
http://127.0.0.1:8080/marketplace
http://127.0.0.1:8080/api/health
```

## Запуск через Nginx

1. Скопировать проект в web root, например:

```bash
/www/wwwroot/cajeer.ru
```

2. Указать root на `public`:

```nginx
root /www/wwwroot/cajeer.ru/public;
```

3. Использовать пример из:

```text
nginx/cajeer-engine.conf
```

4. Выставить права:

```bash
chown -R www-data:www-data storage public/uploads
chmod -R 775 storage public/uploads
```

Для aaPanel пользователь может отличаться: `www:www`.

## CLI

```bash
php bin/cajeer health
php bin/cajeer migrate --database=mysql
php bin/cajeer migrate --database=pgsql
php bin/cajeer cache:clear
php bin/cajeer dle:scan-template themes/default/main.tpl
php bin/cajeer wp:scan-plugin plugins/example/plugin.php
```

## Архитектурный принцип

```text
Cajeer Core = основа
DLE Compatibility = адаптер поверх ядра
WordPress Compatibility = адаптер поверх ядра
```

Нельзя строить ядро как смесь DLE и WordPress. Совместимость должна быть подключаемым слоем.

## Режимы совместимости

### DLE

- `.tpl` шаблоны;
- теги `{title}`, `{short-story}`, `{full-story}`, `{date}`, `{category}`;
- legacy URL mapping;
- импорт новостей/категорий/пользователей;
- модульный адаптер.

### WordPress

- `add_action`, `do_action`;
- `add_filter`, `apply_filters`;
- shortcodes;
- базовые template functions;
- импорт posts/pages/users/comments;
- theme/plugin scanner.

## Важное ограничение

Полная бинарная совместимость со всеми WordPress/DLE плагинами невозможна без фактического встраивания их runtime. Поэтому проект использует уровни совместимости:

```text
L1: импорт данных
L2: шаблоны
L3: URL compatibility
L4: hooks/functions compatibility subset
L5: selected plugin/module compatibility
```

## Структура проекта

```text
cajeer-engine/
├── app/
├── bin/
├── compatibility/
├── config/
├── core/
├── database/
├── docs/
├── modules/
├── nginx/
├── plugins/
├── public/
├── resources/
├── routes/
├── storage/
├── themes/
├── .env.example
├── composer.json
└── README.md
```

## Следующие этапы

1. Довести Content Module до CRUD.
2. Добавить полноценную админку.
3. Реализовать Migration Center.
4. Реализовать DLE importer.
5. Реализовать WordPress importer.
6. Добавить marketplace package installer.
7. Добавить RBAC и audit log.
8. Добавить installer web wizard.
9. Добавить update/rollback manager.
10. Добавить compatibility report.
