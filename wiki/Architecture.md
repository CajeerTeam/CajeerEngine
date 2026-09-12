# Архитектура

CajeerEngine строится как API-first CMS с собственным PHP-ядром и Symfony Components.

## Слои

1. HTTP Kernel.
2. Config/DI.
3. Database layer.
4. Content Core.
5. Security Core.
6. Extension Runtime.
7. Admin UI через REST API.
8. Headless Frontends через REST/OpenAPI.

## Не используется

Twig не используется как шаблонизатор или основа theme engine. Для серверной шаблонизации используется Cajeer Template Engine `.cjr`.
