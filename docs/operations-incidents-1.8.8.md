# CajeerEngine 1.9.0-rc.1 — On-call Incident Runbooks

## Общий порядок реагирования

**Подтверждение:** UTC, владелец инцидента, степень влияния, affected users, текущий релизный SHA, последнее изменение и время первой ошибки. **Изоляция:** не допускайте новых записей в потенциально повреждённое хранилище; автоматический повтор AI/webhook при неопределённом результате запрещён. **Восстановление:** используйте только подтверждённые backup и документированные процедуры, не редактируйте migration journal/queue receipts/update transaction вручную. **Закрытие:** пост-инцидентный протокол, план защиты от повторения, проверка сигналов мониторинга.

## 1. HTTP 503 / sudden 5xx

```bash
php bin/cajeer maintenance:status
php bin/cajeer ops:check
php bin/cajeer ops:report
php bin/cajeer update:status
```

Если maintenance включён на запланированное окно — не снимайте gate до завершения postchecks. Иначе проверьте Nginx → PHP-FPM, ошибки журнала и доступ DB/storage/lock, соответствие FPM и CLI PHP 8.4.1+ и права runtime. `health/live` 200 + `health/ready` 503 указывает на проблему готовности, а не автоматическую неисправность сетевого слоя. Устраните причину, проверьте страницу, логин, API и фоновые задания. Не запускайте бесконечные рестарты, если есть `active.json` или pending restore.

## 2. Очередь зависла / рост DLQ

```bash
php bin/cajeer queue:diagnostics
php bin/cajeer queue:failed --queue=default
php bin/cajeer schedule:due
php bin/cajeer ops:report
```

Проверьте доступность выбранного `QUEUE_DRIVER`, worker heartbeat, load DB/Redis, lease expiry и idempotency keys. При ошибке после внешнего side effect задание **не** повторяется до согласования с получателем. Контроль конкуренции scheduler/worker — [queue & scheduler](queue-scheduler.md). Для AI provider failure проверьте, был ли уже потрачен токен-бюджет, и никогда не реплеите платный запрос при неизвестном исходе. Если невозможно восстановить transport без риска дубликатов, удерживайте очередь изолированной и эскалируйте владельцу данных.

## 3. Обновление остановилось / migration failed

```bash
php bin/cajeer update:status
php bin/cajeer maintenance:status
php bin/cajeer migrate:status
```

Остановите дополнительные writers, сохраните transaction journal и backup, оцените повреждения схемы. Если транзакция активна — штатное `update:rollback`; если update уже committed, rollback `--yes` удаляет последующие записи и требует отдельного решения. Для `update:run` статус `running` не означает, что операция безопасна для повторения: просмотрите активный journal. Следуйте [deployment runbook](operations-deployment-1.8.8.md). Не принуждайте миграции и не удаляйте строку checksum без расследования.

## 4. Backup stale / restore failed

```bash
php bin/cajeer backup:list
php bin/cajeer ops:report
php bin/cajeer maintenance:status
```

Проверьте объем свободного места, права доступа к ключу и инструментам `pg_dump`, лимит subprocess, целостность офсайт-хранилища. Не удаляйте предыдущую проверенную копию после нового успешного `backup:create`: сначала проведите `backup:verify` и изолированный drill. Проваленный restore оставляет durable marker — удерживайте maintenance. [Recovery runbook](operations-recovery-1.8.8.md).

## 5. Отклонение авторизации / подозрение на утечку

```bash
php bin/cajeer security:doctor
php bin/cajeer ops:report
php bin/cajeer keys:status
```

Независимо проверьте IdP, журналы отказов, CSRF/cookie/session expiry, scopes API token, role RBAC и факт ротации ключей. При достоверной компрометации изолируйте ключ/провайдера, инициируйте отзыв, сохраните логи и оцените другие сервисы, использовавшие тот же секрет. Не отключайте `SECURITY_REQUIRE_AUTH`, не включайте `APP_DEBUG` в публичном HTTP и не выкладывайте токены в отчёты. [Security operations](operations-security-1.8.8.md).

## 6. Потеря файлов/DB или массовая порча данных

Зафиксируйте последнее успешно подтверждённое состояние и возможное продолжение записи. Отключите внешние writers и web traffic; оцените точку восстановления отдельно для DB, file queue, media, extensions и external connectors. Для репетиции используйте изолированный `backup:drill`, для production — подписанные исходные файлы и `backup:import` / `backup:restore --yes` по [DR](operations-recovery-1.8.8.md). Нельзя утверждать достижение RPO/RTO, пока реально не проверены HTTP/авторизация/worker и не сверено последнее восстановленное изменение.

## 7. Зависание scheduler / длительная AI Automation

```bash
php bin/cajeer schedule:due
php bin/cajeer queue:diagnostics
php bin/cajeer ops:report
```

Сверьте последний успешный слот, текущие systemd таймеры и persisted состояние AI. Если проблема связана с чужими accounts или отозванными правами, ожидаемые задания должны отказываться безопасно. При подозрении на неопределённый биллинг сначала включите `AI_AUTOMATION_ENABLED=false`, перезапустите новые worker/dispatcher процессы, но учитывайте, что уже отправленный запрос нельзя отменить локальным флагом. Не удаляйте квитанции и счета для «очистки» статуса.

## Коммуникация и postmortem

Первое сообщение инцидента содержит только impact, время UTC и тип деградации; без cookie, JSON-тел, дампов БД и секретов. После восстановления приложите status before/after, идентификатор *подписанного* build, реальные результаты тестов и причины недоступности. Новые автоматические retry, аварийные переключатели и процедуры rollback должны иметь проверенные negative tests до следующего релиза.
