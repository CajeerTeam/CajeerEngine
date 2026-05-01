# Архитектура Cajeer Engine

## 1. Назначение

Cajeer Engine — самостоятельная CMS-платформа на PHP, предназначенная для замены DLE и WordPress.

Проект должен поддерживать:

- нативное ядро CMS;
- нативные темы, плагины и модули;
- импорт из DLE и WordPress;
- частичную совместимость с DLE/WordPress через адаптеры;
- PostgreSQL и MySQL/MariaDB;
- Nginx-first deployment;
- Marketplace расширений.

## 2. Архитектурные слои

```text
app/
├── Admin/
├── Api/
├── Console/
├── Frontend/
└── Install/

core/
├── Auth/
├── Cache/
├── Config/
├── Content/
├── Database/
├── Events/
├── Extensions/
├── Filesystem/
├── Http/
├── Marketplace/
├── Routing/
├── Security/
└── Users/

compat/
├── WordPress/
└── DLE/

database/
├── migrations/
├── schema/
│   ├── mysql/
│   └── postgresql/
└── seeders/

extensions/
├── plugins/
├── modules/
├── themes/
└── widgets/

public/
├── index.php
├── admin.php
├── api.php
└── assets/

storage/
├── cache/
├── compiled_tpl/
├── logs/
├── sessions/
└── uploads/
```

## 3. Core CMS

Ядро отвечает за:

- HTTP request/response;
- routing;
- config;
- события;
- кеш;
- пользователи и роли;
- контент;
- медиа;
- безопасность;
- расширения;
- Marketplace API.

## 4. Database Layer

Cajeer Engine использует DBAL поверх PDO.

Поддерживаемые драйверы:

- PostgreSQL;
- MySQL;
- MariaDB.

Выбор СУБД выполняется при установке. Одновременная работа с двумя СУБД не является обязательной нормой; исключение — режим миграции из legacy-источника.

## 5. Content Model

Базовые сущности:

- `content_items`;
- `content_types`;
- `content_fields`;
- `content_taxonomies`;
- `content_terms`;
- `content_comments`;
- `content_meta`;
- `content_revisions`;
- `users`;
- `roles`;
- `permissions`;
- `media_files`.

## 6. Extension API

Нативные расширения делятся на:

- `plugin`;
- `module`;
- `theme`;
- `widget`;
- `adapter`.

Каждое расширение должно иметь manifest-файл:

```json
{
  "name": "cajeer/example",
  "title": "Example Plugin",
  "type": "plugin",
  "version": "1.0.0",
  "engine": ">=1.0.0",
  "entry": "src/Plugin.php"
}
```

## 7. Compatibility Layers

Compatibility Layer не заменяет DLE/WordPress полностью.

Его задача:

- импортировать данные;
- запускать часть шаблонов;
- запускать часть расширений;
- показывать отчёт совместимости;
- предлагать адаптацию.

## 8. Admin Panel

Админка должна включать:

- управление контентом;
- управление типами контента;
- категории и теги;
- комментарии;
- медиа;
- пользователи, роли, права;
- темы;
- плагины;
- модули;
- Marketplace;
- Compatibility Scanner;
- импорт DLE/WordPress;
- системные настройки;
- логи и диагностика.

## 9. Marketplace

Marketplace должен поддерживать compatibility badges:

- `Native`;
- `WordPress-compatible`;
- `DLE-compatible`;
- `Requires adaptation`;
- `Unsupported`.
