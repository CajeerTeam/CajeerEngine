# CajeerEngine 1.1.3: API и безопасность

## API

`api/openapi.yaml` — OpenAPI 3.1.1 в JSON-совместимом YAML. `core/Http/RouteRegistry.php` — реестр маршрутов и разрешений, используемый Router. `php bin/cajeer api:lint --strict --json` обнаруживает несовпадение методов, путей, разрешений, operationId и ссылок схем. Runtime валидирует JSON-запросы по контракту; диагностические ответы пока используют расширяемые схемы, а полная проверка каждого фактического ответа требует HTTP integration tests. HEAD обслуживается Symfony как GET, не как отдельная операция OpenAPI.

API закрыт по умолчанию; старые настройки отключения auth больше не открывают защищённые маршруты. `/health`, login и публичные страницы остаются доступны. Для внешних клиентов используйте Bearer или X-API-Token. Токены привязаны к активному пользователю; прежние токены без владельца требуется заменить. Актуальные разрешения ограничивают scopes токена; disable пользователя отзывает его токены. Для выдачи CLI-токена укажите `--user-id` активного пользователя. Административные операции управления учётными записями, привилегиями, расширениями и обновлениями требуют `*`.

Браузерная админка использует `X-Cajeer-Browser: 1` при login; сервер возвращает HttpOnly SameSite=Strict cookie `ce_session`, Secure на HTTPS, и CSRF token. Отправляйте X-CSRF-Token при изменяющих запросах. HTTPS обязателен, исключение — локальный loopback для разработки. Старые локальные токены браузера удаляются при запуске новой админки. API-клиенты без browser header продолжают получать Bearer token. После включения/отключения 2FA все сессии отзываются, нужен повторный вход; операция требует пароль и текущий 2FA код, если он уже включён.

## Установка и web server

Выполните `php bin/cajeer install:web-token` под пользователем PHP-FPM, затем вставьте одноразовый токен в `/install`. Токен действует 30 минут, хранится хешированным. CLI install не требует web-token. Публичный API installer/run заблокирован. Для сайтов используйте HTTPS; document root — только `public/`. Генератор Nginx и example разрешают исполнение только index.php и системных entrypoints. Пересоздайте и проверьте существующий Nginx конфиг перед эксплуатацией; вручную не добавляйте универсальное PHP location.

## Content, media, outbound

Для upload обязательно ext-fileinfo. Разрешены только согласованные расширения и MIME, для изображений проверяются размеры; SVG отключён. Встроенная тема очищает rich HTML по allowlist при наличии ext-dom; без него выводит экранированный текст. Пользовательские `.cjr`-шаблоны и PHP extensions остаются доверенным кодом, не sandbox для недоверенных авторов. Для webhook и удалённой release metadata требуется ext-curl: только публичные HTTP(S) адреса, стандартные порты, без redirects, DNS/IP validation и привязка IP к соединению. Внутренние webhook endpoints и нестандартные порты сознательно запрещены. Дополнительно настройте сетевой egress firewall.

## Временные ограничения

До 1.1.4 production update apply/rollback, включая `/upgrade`, отключены. Планирование доступно; проверка локального ZIP требует доверенный внешний SHA256 и manifest v5. SHA256 не заменяет цифровую подпись. `--force` не отключает проверку. Для изолированного тестового CLI окружения возможен только явный `UPDATE_ALLOW_UNSAFE_DEV_APPLY=true` с APP_ENV=local/dev/testing. Не включайте его в production. Подписание, database backup/restore и crash recovery остаются задачами 1.1.4. Maintenance можно снять через защищённый API или CLI.

Import v2 сверяет checksum; встроенные media проверяются. До транзакционного import 1.1.5 запрещён delete_missing_content; импорт пользователей/ролей и extension registry не применяется, требуется ручная настройка. Import всё ещё не атомарен. Для API указывайте basename JSON из storage/app/imports или exports. Не импортируйте недоверенные данные без предварительного анализа и backup.

## Проверки и SDK

```sh
php bin/cajeer api:lint --strict --json
php tools/security-regression.php
php tools/generate-api-sdk.php --check
php tools/release-regression.php
php tools/ci-check.php --json
```

SDK: `packages/php-sdk/src/RestClient.php` и `packages/ts-sdk/src/index.ts` предоставляют `call(operationId, ...)` с каталогом 119 операций, синхронизируемым через `php tools/generate-api-sdk.php`. Базовый URL включает `/api/v1`. PHP SDK использует cURL, TypeScript — fetch. JSON-клиенты используют Bearer; TypeScript также принимает FormData для multipart. Сгенерированный каталог покрывает маршруты, но не является полной генерацией типизированных response DTO.

Release CI запускает HTTP/DB smoke для SQLite/PostgreSQL и регрессионные тесты. Полный production допуск требует успешного CI с настоящими Composer-зависимостями и lock-файлом, а также независимого security review.
