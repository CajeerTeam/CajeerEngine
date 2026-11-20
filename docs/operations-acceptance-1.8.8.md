# CajeerEngine 1.9.9 — Operational Acceptance Checklist

## Блокирующие условия

Заполните это как протокол для **конкретного** staging/production deployment. «Документ есть» не равно «операция прошла». Для каждого шага фиксируйте фактический exit code, дату, исполнитель, commit/sha/host и путь к защищённому evidence без секретов. Если обязательный тест не запускается из-за отсутствующих Composer, PHP extension, СУБД, TLS или systemd — результат **NOT RUN / NO-GO**, а не PASS.

| Gate | Проверка | Требуемое свидетельство |
|---|---|---|
| Версия и артефакт | Source/dist версии 1.8.8, Ed25519, SHA-256 | Проверка на точном, утверждённом дистрибутиве |
| PHP / зависимость | PHP CLI/FPM >=8.4.1 и <9, расширения, lock dependencies | `composer validate --strict`, PHP lint, Composer audit/CI |
| Документация | Ссылки, CLI, ключи env, runbooks | `php tools/operations-docs-check.php` (0 ошибок), регрессия отказа |
| DB | Подключение, миграции 0001–0043, integrity, isolation | Результат live staging migration + regression real DB |
| Auth / Security | Cookie+CSRF, OIDC, RBAC, API токены, webhook SSRF | Негативные HTTP+E2E тесты, журнал security-review |
| Queues | delivery/lease/retry/DLQ/worker stop | Fault injection и отсутствие новых duplicate side effects |
| Scheduler / AI | slot-idempotency, pause/resume, budget, права | Реальные staging end-to-end с тестовым провайдером |
| Performance | Mixed read/write, p95, error rate, long soak | Отчёт с аппаратной конфигурацией и dataset |
| Backup / DR | Backup creation + authenticated verify + encrypted export + cold restore | Evidence с измеренным RTO/RPO и доступностью ключа |
| Upgrade / Rollback | Signed update, preflight, failure, rollback | Подтверждённый real dist smoke и ручной cutover drill |
| Ops | Ready 200 после старта; deliberate 503 в maintenance | Monitoring, alert routing, systemd restarts, on-call handoff |
| Документация окружения | env, версии Nginx/FPM/БД, права, внешний storage | Конфигурационная ведомость без секретов |

## Выполняемые проверки без модификации production-данных

```bash
php tools/operations-docs-check.php
php tools/operations-docs-regression.php
php tools/ci-check.php
php tools/build-release.php --dry-run --all --json
php bin/cajeer doctor --json --no-report
php bin/cajeer ops:check
php bin/cajeer ops:report
php bin/cajeer migrate:status
```

Нагрузочные/E2E проверки проводят на **изолированном** disposable стенде с копией данных, в которой удалены персональные сведения и credentials; использовать production DB для `e2e`/`staging load` нельзя. При необходимости собирайте метрики для p95 и пиков; результаты локального smoke не заменяют реальную длительную проверку.

## Проверки в изолированной CI среде

```bash
php tools/database-compatibility-regression.php
php tools/deployment-recovery-acceptance.php
php tools/runtime-queue-resilience-regression.php
php tools/performance-load-regression.php
bash tools/e2e-acceptance.sh
```

Полный `vendor/bin/phpunit`/PHPStan, сервисные PostgreSQL/MySQL/MariaDB, GitHub Actions и production dist smoke обязательны, где применимо. Их прохождение в локальном контейнере не предполагается и должно быть подтверждено отчётом CI. Релиз не может быть назван готовым только после проверки markdown или `php -l`.

## Передача дежурству

Зафиксируйте текущую версию, расположение signed artifact и trust key, адрес и права escalation contact, политику backup retention, реальную периодичность `backup:drill`, согласованные RTO/RPO и SLO, правила доступа к `maintenance:on/off`, время истечения TLS/IdP ключей и внешних лицензий. Подписи release owner, security owner и on-call owner — условие Go. При невыполнении хотя бы одного P0 или отсутствующем evidence оставьте NO-GO, опубликуйте список блокеров и не отключайте maintenance.

Подробнее: [общий справочник](operations-handbook-1.8.8.md), [upgrade](operations-deployment-1.8.8.md), [recovery](operations-recovery-1.8.8.md), [incident runbooks](operations-incidents-1.8.8.md).
