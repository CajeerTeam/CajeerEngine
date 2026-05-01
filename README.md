# Cajeer Engine

**Cajeer Engine** — PHP CMS-платформа для замены DLE и WordPress с поддержкой PostgreSQL/MySQL, Nginx-first развёртывания и слоями совместимости для шаблонов, плагинов и модулей.

## Цель

Cajeer Engine строится как самостоятельная CMS, а не как форк DLE или WordPress. Основная задача — современное ядро, нативная система расширений и controlled compatibility layer для миграции и частичного запуска legacy-экосистем DLE/WordPress.

## Стек

- PHP 8.2+
- Nginx
- PostgreSQL
- MySQL/MariaDB
- PDO DBAL
- Composer autoload
- CLI-инструменты Cajeer

## Архитектурная модель

```text
Cajeer Engine
├── Core CMS
├── Native Extension API
├── Compatibility Layers
│   ├── WordPress Compatibility Layer
│   └── DLE Compatibility Layer
├── Migration Toolkit
│   ├── WordPress to Cajeer
│   └── DLE to Cajeer
├── Marketplace
└── Admin Panel
```

## Основные подсистемы

- `core` — ядро CMS: HTTP, routing, config, events, cache, auth, users, roles, content, media, security.
- `database` — DBAL и миграции для PostgreSQL/MySQL.
- `extensions` — нативные темы, плагины, модули, виджеты и адаптеры.
- `compat/WordPress` — hooks/actions/filters, options API subset, shortcode API subset, classic theme adapter, scanner.
- `compat/DLE` — `.tpl` compiler, template tags, legacy runtime, module runner, scanner.
- `migration` — импорт DLE/WordPress в нативную модель Cajeer.
- `admin` — панель управления, включая Compatibility Scanner.
- `marketplace` — каталог расширений с compatibility badges.

## Принцип совместимости

Cajeer Engine не должен обещать запуск любого WordPress-плагина или любого DLE-модуля без адаптации.

Корректная модель:

> Cajeer Engine предоставляет слои совместимости для миграции, анализа, адаптации и частичного запуска шаблонов, плагинов и модулей DLE/WordPress. Совместимость подтверждается Compatibility Scanner.

## Документация

- [Архитектура](docs/ARCHITECTURE.md)
- [Совместимость DLE/WordPress](docs/COMPATIBILITY.md)
- [Roadmap](docs/ROADMAP.md)

## Лицензия

Apache-2.0
