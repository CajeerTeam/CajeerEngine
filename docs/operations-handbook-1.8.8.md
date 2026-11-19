# CajeerEngine 1.8.8 — Руководство оператора

**Статус:** инструкция для проверки и эксплуатации релиз-кандидата, не сертификат прохождения production-приёмки. Фиксируйте ссылку на конкретный commit, SHA-256 подписанного dist и протокол Go/No-Go. Не переносите успешные результаты из другой установки автоматически.

## Поддерживаемый профиль

Рекомендуемый проверяемый контур: один выделенный Linux-хост с systemd, Nginx/TLS, PHP-FPM и CLI PHP 8.4.1+, PostgreSQL 17+, локальный `storage/` и очередь PostgreSQL либо файловая очередь при наличии общего durable storage. Проект допускает альтернативные режимы (SQLite demo/local, MySQL 8.4, MariaDB 10.11, Redis), но **степень их сертификации зависит от фактически выполненных проверок**. RabbitMQ не является поддерживаемым путём production QueueFactory. Multi-node, S3 и внешние поисковые/очередные хранилища требуют отдельного согласованного плана восстановления.

Используйте проверенный `-dist.zip`, а не `source.zip` в качестве готового развёртывания: dist включает предварительно собранные зависимости и административные ресурсы. На рабочем сервере не выполняйте `composer update` или `npm install` от имени PHP-FPM. Файл `public/` — единственный web root; `.env`, `storage`, журналы, ключи и резервные копии никогда не должны обслуживаться HTTP-сервером.

## Операционные роли

| Роль | Доступ | Разрешённые действия |
|---|---|---|
| Runtime (PHP-FPM/worker) | Запись только в runtime-каталоги, доступ к секретам приложения | Запросы HTTP, обработка заданий |
| Оператор релиза | Обновления и подписанные артефакты, журнал изменений | Maintenance, backup, `update:apply`, восстановление после утверждения |
| Оператор БД | Минимально необходимые права БД/backup | Проверка схемы, restore rehearsal, мониторинг блокировок |
| Security/release approver | Доступ к защищённым отчётам и ключу доверия (не к signing private key) | Go/No-Go, отзыв ключей, инциденты |

Release signing key хранится **вне** CMS и production-хоста; `UPDATE_TRUSTED_PUBLIC_KEY_FILE` — публичный ключ доверия, `BACKUP_KEY_FILE` — отдельный секрет для шифрованных резервных копий. Секреты нельзя записывать в логи, issue, отчёты, URL или аргументы командной строки.

## Начало смены (только чтение)

Запускайте от доверенного shell-оператора в корне установленного приложения. Ожидаемый exit code `0` для исправной установки; ошибку любого шага фиксируйте как инцидент, не обнуляйте вручную.

```bash
php bin/cajeer maintenance:status
php bin/cajeer ops:check
php bin/cajeer ops:report
php bin/cajeer queue:diagnostics
php bin/cajeer schedule:due
php bin/cajeer update:status
php bin/cajeer backup:list
```

`GET /health/live` подтверждает лишь выполнение PHP front controller; `GET /health/ready` (200/503) проверяет готовность зависимостей. `ops:report` возвращает безопасный для оператора отчёт, но всё равно не предназначен для публичного размещения. Проверяйте systemd, Nginx/PHP-FPM, доступность TLS и внешние зависимости отдельно. Все команды с `restore`, `apply`, `run`, `retry`, `maintenance:off` меняют состояние и не входят в рутинный read-only preflight.

## Документы по процедурам

- [Развёртывание и подписанные обновления](operations-deployment-1.8.8.md) — clean install, canary, maintenance, rollback, остановка worker.
- [Наблюдаемость и очереди](operations-monitoring-1.8.8.md) — health, metrics, логи, мониторинг scheduler, AI Automation.
- [Резервные копии и восстановление](operations-recovery-1.8.8.md) — проверка копий, encrypted export, изолированный drill, cold restore.
- [Безопасность и секреты](operations-security-1.8.8.md) — границы доверия, OIDC, ротация ключей, внешние AI и webhook.
- [Runbooks инцидентов](operations-incidents-1.8.8.md) — 503, потеря очереди, ошибки миграций, компрометация секретов, data loss.
- [Матрица эксплуатационной приёмки](operations-acceptance-1.8.8.md) — обязательные evidence, список непройденных проверок, передача дежурству.

Исторические технические подробности: [Production Deployment](production-deployment.md), [Update & Backup](update-backup.md), [Disaster Recovery](disaster-recovery.md), [Observability](observability-operations.md), [Database Compatibility](database-compatibility-1.8.4.md), [Deployment Recovery Acceptance](deployment-recovery-1.8.7.md), [AI Automation GA](ai-automation-ga-1.8.0.md).

## Регламент

**Ежедневно:** контролируйте readiness, 5xx, disk, backup freshness, queued/failed jobs, scheduler freshness и истечение сертификата. **Еженедельно:** проверяйте, что шифрованная копия находится *в другом failure domain* и доступна с отдельным ключом, анализируйте медленные SQL и рост логов. **После каждого релиза:** сверяйте SHA, версии API/SDK, целостность миграций, scheduler, доступ администратора и реальные CMS маршруты. **Периодически:** проводите восстановление на изолированном стенде с измерением RTO/RPO; частоту устанавливает владелец SLO. Не объявляйте backup пригодным к восстановлению только по факту его создания.

## Доказательства смены

В защищённый журнал заносите время UTC, операторов, версию и SHA релиза, `maintenance:status`, `ops:report`, результат backup verification/drill, состояния служб, затронутые операции и решение Go/No-Go. Секреты, тела webhook, cookie, токены доступа, приватные AI-промпты и полные payload заданий не сохраняйте. Хранение таких evidence зависит от локальной privacy/retention политики.
