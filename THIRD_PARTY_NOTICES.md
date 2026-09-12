# Уведомления о сторонних компонентах

Этот файл содержит сведения о сторонних компонентах, которые могут использоваться проектом `CajeerEngine`.

Полный и окончательный список зависимостей должен проверяться по manifest-файлам проекта, lock-файлам и фактической сборке релиза.

## Общие правила

- Сторонние компоненты сохраняют свои оригинальные лицензии.
- Наличие компонента в этом файле не означает передачу прав на товарные знаки его владельцев.
- При распространении релиза необходимо соблюдать условия лицензий всех включённых зависимостей.
- Если в релиз включаются prebuilt assets, bundled packages или vendor-код, их лицензии должны быть проверены отдельно.
- Для production-релиза рекомендуется формировать этот файл автоматически из lock-файлов.

## PHP runtime-зависимости из `composer.json`

- `ext-json` — `*`
- `ext-mbstring` — `*`
- `ext-pdo` — `*`
- `ext-pdo_pgsql` — `*`
- `php` — `>=8.4 <9.0`
- `psr/container` — `^2.0`
- `psr/event-dispatcher` — `^1.0`
- `psr/log` — `^3.0`
- `symfony/cache` — `^8.1`
- `symfony/config` — `^8.1`
- `symfony/console` — `^8.1`
- `symfony/dependency-injection` — `^8.1`
- `symfony/dotenv` — `^8.1`
- `symfony/event-dispatcher` — `^8.1`
- `symfony/filesystem` — `^8.1`
- `symfony/finder` — `^8.1`
- `symfony/http-foundation` — `^8.1`
- `symfony/password-hasher` — `^8.1`
- `symfony/rate-limiter` — `^8.1`
- `symfony/routing` — `^8.1`
- `symfony/serializer` — `^8.1`
- `symfony/uid` — `^8.1`
- `symfony/validator` — `^8.1`
- `symfony/yaml` — `^8.1`

## PHP dev-зависимости из `composer.json`

- `phpstan/phpstan` — `^2.1`
- `phpunit/phpunit` — `^12.2`
- `squizlabs/php_codesniffer` — `^3.10`

## Node.js runtime-зависимости из `admin/package.json`

- `@nuxt/devtools` — `latest`
- `nuxt` — `^4.2.0`
- `vue` — `^3.5.0`
## Node.js dev-зависимости из `admin/package.json`

- `typescript` — `5.9.3`
## Node.js runtime-зависимости из `packages/ts-sdk/package.json`

Не обнаружено в текущем manifest-файле.
## Node.js dev-зависимости из `packages/ts-sdk/package.json`

- `typescript` — `5.9.3`

## Инфраструктурные и внешние проекты

Проект может интегрироваться или быть развёрнут вместе со следующими технологиями. Их использование регулируется отдельными лицензиями и условиями соответствующих проектов:

- PHP;
- Symfony Components;
- PostgreSQL;
- MySQL;
- MariaDB;
- Redis;
- RabbitMQ;
- Nginx;
- Apache HTTP Server;
- Nuxt;
- Vue;
- TypeScript;
- OpenAPI;
- Meilisearch;
- OpenSearch;
- S3-compatible storage providers;
- GitFlic Releases / Registry.

## Контакты

Вопросы по лицензиям и правовым уведомлениям:

```text
legal@cajeer.ru
```

Вопросы разработки:

```text
dev@cajeer.ru
```

Сообщения о нарушениях или злоупотреблениях:

```text
abuse@cajeer.ru
```
