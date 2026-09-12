# API

REST API проектируется contract-first. Основной контракт находится в `api/openapi.yaml`.

## Правила

- Все breaking changes проходят через мажорную версию API.
- SDK генерируются или синхронизируются с OpenAPI-контрактом.
- Admin UI не должен обращаться к внутренним PHP-сервисам напрямую.
- Headless Frontends используют только публичный REST API.
