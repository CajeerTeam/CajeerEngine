# CajeerEngine 1.9.1 — External Analytics production acceptance

## Реальный production-контур

Поддерживаемые подключения: Yandex Metrika, Google Analytics 4, Yandex Webmaster, Google Search Console, Matomo, Plausible, Cloudflare Zone Analytics и Cloudflare Web Analytics. Чтение показателей выполняется серверным транспортом по HTTPS с DNS-проверкой, закреплением выбранного IP, запретом перенаправлений, локальных/приватных IP и нестандартных портов. Секреты хранятся в SecretVault, не передаются в queue payload или API-ответах. Для Matomo и сторонних доменов Plausible оператор должен настроить `integrations.allowed_hosts`.

**Рабочие исправления 1.9.1:** Google Search Console sitemap отправляется HTTP PUT (204 без тела — успешный ответ); смена credentials любого аналитического подключения увеличивает ревизию и атомарно удаляет отчёты предыдущего владельца/ключа. Запущенные задачи старой ревизии не должны сохранять результат. В интерфейсе API история предыдущей ревизии не отображается.

## Проверка без внешней сети

```bash
php tools/search-webmaster-regression.php --require-db
php tools/analytics-hub-regression.php --require-db
php tools/analytics-ga-regression.php --require-db
php tools/alternative-analytics-regression.php --require-db
php tools/cloudflare-analytics-regression.php --require-db
php tools/ci-check.php --strict
```

Для набора `--require-db` необходим `pdo_sqlite` и реальные миграции 0001–0043. Дополнительно release CI выполняет проверку PostgreSQL 17/18, MySQL 8.4 и MariaDB 10.11 на изолированных схемах. На локальном окружении без PDO эти тесты нельзя считать выполненными.

## Проверка с настоящим провайдером

На отдельном staging-хосте заведите тестовые property и токены **только** с нужными scopes. Сохраните JSON credentials в файле за пределами репозитория (права `0600`, владелец — оператор), не записывайте его в shell history и не передавайте как аргумент CLI. Для каждого из восьми объявленных поддерживаемых коннекторов выполните `tools/analytics-live-acceptance.php` с переменными окружения `ANALYTICS_ACCEPTANCE_PROVIDER`, `ANALYTICS_ACCEPTANCE_RESOURCE`, `ANALYTICS_ACCEPTANCE_CREDENTIAL_FILE`, `ANALYTICS_ACCEPTANCE_FROM`, `ANALYTICS_ACCEPTANCE_TO`, по необходимости `ANALYTICS_ACCEPTANCE_GOAL`. Для Matomo/self-hosted Plausible установите `ANALYTICS_ACCEPTANCE_ALLOWED_HOSTS` в точный разрешённый домен. Диапазон — от одного до 90 дней, не в будущем.

Скрипт реально обращается к API поставщика и проверяет нормализованную структуру, период и метаданные отчёта. Он выводит только итоговые размеры секций, без токенов, URL или значений метрик. Ошибки завершаются ненулевым кодом и не печатают секреты. Эти проверки **не запускались локально** без внешних credentials; их результаты должны быть приложены к протоколу staging.

## Отказы, повторная синхронизация и контроль затрат

На тестовом подключении выполните: ручной запуск и повторный запуск в пределах idempotency-окна, HTTP 429/503, тайм-аут провайдера, временное отсутствие сети, изменение credentials во время задачи, удаление подключения при исполняющемся задании и долгую простую очередь. Проверьте, что прошлый корректный отчёт сохраняется при сетевой ошибке, а смена credentials очищает его. Зафиксируйте фактические timestamps, status/attempts, очередь pending/failed и время восстановления. Поскольку политика retry зависит от транспортного драйвера очереди, её необходимо проверять на целевом драйвере.

## Граница сертификации

`VERSION=1.9.1` и локально зелёный source-аудит не являются подтверждением официального GA. Для production-допуска нужны: успешный обязательный CI на точном commit, собранный dist с Composer-зависимостями, проверенная подпись/контрольные суммы официальных артефактов, результаты каждого требуемого live-коннектора, restore drill и решение Go/No-Go. Релизный секрет signing key должен оставаться вне CMS и runtime-хоста.
