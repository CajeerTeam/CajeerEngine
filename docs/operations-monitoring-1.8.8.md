# CajeerEngine 1.9.5 — Monitoring, Workers & Scheduler

## Endpoints и режим доступа

`GET /health/live` измеряет доступность front controller. `GET /health/ready` выдаёт 200 или 503 по обязательным зависимостям (схема/DB/storage/maintenance); не считайте 200 на live признаком готовности CMS. `GET /metrics` появляется только после установки отдельного токена `OBSERVABILITY_METRICS_TOKEN` (не менее 32 символов). Ограничьте доступ к metrics сетью и TLS, передавайте токен в HTTP-заголовке, не в URL. Не помещайте metrics token в браузер или CI public logs. Админское `GET /api/v1/system/operations` требует `system:read`.

```bash
php bin/cajeer ops:check
php bin/cajeer ops:report
php bin/cajeer ops:metrics
php bin/cajeer queue:diagnostics
php bin/cajeer queue:failed --queue=default
php bin/cajeer schedule:due
```

`ops:report` должен оставаться доступным в maintenance; при частичном отказе он сообщает об деградировавших зависимостях, тогда как readiness блокирует лишь критичные. Метрики маршрутов агрегируются по нормализованным именам и классам статусов, SQL-текст/параметры и HTTP payload не записываются в основную runtime-телеметрию. Полное описание: [observability-operations](observability-operations.md).

## Конфигурация

Следующие ключи имеются в `.env.example`; устанавливайте индивидуальные безопасные значения в защищённой `.env` и перезапускайте PHP-FPM/worker после изменения env:

```dotenv
APP_ENV=production
APP_DEBUG=false
OBSERVABILITY_MIN_FREE_BYTES=104857600
OBSERVABILITY_BACKUP_MAX_AGE=86400
OBSERVABILITY_WORKER_MAX_AGE=300
OBSERVABILITY_QUEUE_MAX_AGE=300
OBSERVABILITY_TRACE_SAMPLE_RATE=0.1
```

Секрет `OBSERVABILITY_METRICS_TOKEN` генерируется отдельно через криптостойкий источник и не публикуется в документах. Для внешнего OTLP заполните `OBSERVABILITY_OTLP_ENDPOINT`, `OBSERVABILITY_OTLP_TOKEN`; при сбое collector приложение не должно останавливаться. Spool bounded, но переполнение приводит к потере новых диагностических trace-сэмплов, не бизнес-транзакций.

## Health и SLO

Рекомендуемые сигналы для Alertmanager (значения выбираются на реальном staging): готовность `cajeer_ready`, `cajeer_http_requests_total`, `cajeer_queue_jobs{state}`, `cajeer_queue_oldest_ready_age_seconds`, `cajeer_worker_last_seen_timestamp_seconds`, `cajeer_scheduler_last_success_timestamp_seconds`, `cajeer_backup_last_success_timestamp_seconds`, `cajeer_dependency_up`, `cajeer_disk_free_bytes`. P95 считать из histogram rate, а не из накопительного snapshot за всё время работы. Отдельно отслеживайте 5xx, auth 429, медленные SQL, свободные inode, срок действия TLS, отказ загрузки media и непредвиденный рост расходов AI.

**Критическая тревога:** readiness 503 без согласованного maintenance, ошибки backup verification, невозможность восстановления очереди, отказ DB и признаки повторной платной AI-операции. **Предупреждение:** старый worker heartbeat без новой нагрузки (может быть ложноположительным), рост количества queued jobs, возраст последнего backup, низкий disk.

## Очереди: регулярная эксплуатация

1. Проверьте driver (`QUEUE_DRIVER`) и состояние сервиса. Для файла сохраните durability `storage/app/queue`; для PostgreSQL контролируйте транзакции и задержку; Redis требует независимого backup/restore и не входит в обычную локальную backup-копию.
2. Сравните `queue:diagnostics` с реальной работой очереди; счётчики и heartbeat не доказывают выполнение конкретного задания.
3. Перед ручным retry выясните, мог ли внешний side effect завершиться до потери ack. Webhooks используют idempotency/receipt, но удалённый получатель обязан дедуплицировать. Для AI не допускается автоматически повторять неопределённый платный запрос.
4. При просроченной резервизации сверяйте runner, lease, время UTC, shutdown и запись attempts. Не изменяйте job JSON/SQL вручную.

Для планировщика важно **последнее успешное выполнение задачи**, а не только то, что timer запущен. Сохраняйте единый источник времени, контролируйте конфигурацию `SCHEDULER_DRIVER`. Если очередь недоступна, не форсируйте серию scheduler runs до анализа failed/outstanding slots. Подробности в [queue-scheduler](queue-scheduler.md) и [runtime-queue-resilience](runtime-queue-resilience-1.8.3.md).

## AI Automation

`AI_AUTOMATION_ENABLED=false` — emergency switch для *новых/queued* фоновых AI-вызовов после перезапуска процессов; он не отзывает уже отправленный провайдеру запрос. В кризисе дополнительно ограничьте исходящий трафик/выделенные credentials согласно процедуре реагирования, но не удаляйте историю слотов и billing evidence. Контролируйте owner permissions, месячные budget limits, delivery failures и задержку между запланированным и фактическим start. [AI Automation GA](ai-automation-ga-1.8.0.md) и [Security Acceptance](ai-automation-security-acceptance-1.7.9.md).

## Логи и диагностика

Runtime: `storage/logs/runtime.jsonl` с ротацией; старые аудит-журналы и extension logs требуют отдельной политики хранения. Сопоставляйте `X-Request-Id`/`traceparent` с Nginx access/error logs, PHP-FPM error log, journalctl для scheduler и worker; не копируйте тело входящего запроса, авторизационные заголовки, пароли и приватные AI-промпты в тикеты.

```bash
php bin/cajeer ops:traces:flush
php bin/cajeer doctor --json --no-report
```

Если Prometheus/collector недоступен: проверьте DNS/TLS/токен и настройки экспортёра; не отключайте авторизацию `/metrics` ради диагностики. Перед очисткой логов убедитесь в retention и наличии внешнего хранилища. После исправления — обязательно проверьте `ops:check` и пример реального HTTP запроса.
