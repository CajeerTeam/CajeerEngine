# Cajeer Engine Wiki

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

## Разделы Wiki

- [Архитектура](Architecture.md)
- [Совместимость DLE/WordPress](Compatibility.md)
- [Roadmap](Roadmap.md)

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

## Принцип совместимости

Cajeer Engine не должен обещать запуск любого WordPress-плагина или любого DLE-модуля без адаптации.

Корректная модель:

> Cajeer Engine предоставляет слои совместимости для миграции, анализа, адаптации и частичного запуска шаблонов, плагинов и модулей DLE/WordPress. Совместимость подтверждается Compatibility Scanner.
