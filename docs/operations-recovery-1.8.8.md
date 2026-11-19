# CajeerEngine 1.8.9 — Backups, Recovery & DR

## Что входит в копию

`backup:create` фиксирует исходную идентичность БД и управляемые файлы; локальное хранилище, расширения и файловая очередь включаются только для соответствующих драйверов. Не включаются внешняя S3, Redis, RabbitMQ, поисковые сервисы и сторонние провайдеры: для них нужен отдельный согласованный snapshot. Локальная backup-директория не эквивалентна офсайт-копии. Данные в локальном backup за исключением `.env` могут оставаться открытыми — защищайте каталог правами и дисковым шифрованием. Внешний `backup:export` шифрует весь bundle через secretstream.

## Ключи, пространство и проверка

`BACKUP_KEY_FILE` — абсолютный путь к **отдельному** защищённому ключу, не входящему в managed project/backup. Сначала создайте ключ один раз, храните независимую резервную копию секретного файла. Без ключа encrypted `.cebackup` восстановить нельзя.

```bash
php bin/cajeer backup:keygen --key=/secure/keys/cajeer-backup.key
php bin/cajeer backup:create
php bin/cajeer backup:list
php bin/cajeer backup:verify --id=BACKUP_ID
```

`BACKUP_ID` заменяется идентификатором, реально возвращённым `backup:list`; генерацию ключа **не повторяйте** для уже существующих backup. Убедитесь, что `BACKUP_COMMAND_TIMEOUT_SECONDS` достаточно для реального размера БД, и что объем свободного места покрывает dump, workspace и проверку. Для PostgreSQL нужны `pg_dump`, `pg_restore`, `createdb`, `dropdb` и права отдельной роли; для SQLite соответствующий PDO или документированный Python fallback. Не назначайте DB superuser ради удобства.

## Encrypted offsite export и тестовая репетиция

```bash
php bin/cajeer backup:export --id=BACKUP_ID --file=/secure/offsite/cajeer-20261119.cebackup
php bin/cajeer backup:drill --file=/secure/offsite/cajeer-20261119.cebackup --workspace=/secure/recovery-drill-unique --rto-seconds=300 --rpo-seconds=86400
```

Обе команды выполняются с корректным `BACKUP_KEY_FILE`. Имя workspace должно быть новым, путь — защищённым, копия — доставленной в независимый failure domain. Drill возвращает оценку времени восстановления данных и возраста snapshot; `application_boot_tested=false` означает, что реальный HTTP/FPM/auth всё ещё **не** проверены. Указанные `300` и `86400` — примеры бюджета проверки, а не обещанные SLA. В PostgreSQL создаётся отдельная временная база `ce_dr_*`; требуется достаточный объем хранилища и изоляция расширений, способных выполнять внешние запросы. Для MySQL/MariaDB изолированный автоматический drill не поддерживается: используйте физически выделенный disposable сервер с исходным identity.

## Cold recovery (разрушительная операция)

1. Объявите incident, изолируйте трафик, остановите все writer/worker/scheduler, удерживайте maintenance. Проверьте целостность, timestamp и происхождение backup и ключа.
2. Разверните совместимый код в **том же абсолютном пути**, с прежним UID/GID, драйвером/именем БД и путями runtime. Внешние dependencies восстановите независимо; никогда не выполняйте `install` поверх восстанавливаемой БД.
3. Импортируйте bundle и проверьте snapshot до разрушительного restore. Убедитесь, что изолированный recovered host не использует production credentials для исходящих операций (AI/webhooks/email) до разрешения.

```bash
php bin/cajeer maintenance:on --message=recovery
php bin/cajeer backup:import --file=/secure/offsite/cajeer-20261119.cebackup
php bin/cajeer backup:verify --id=BACKUP_ID
php bin/cajeer backup:restore --id=BACKUP_ID --yes
php bin/cajeer migrate:status
php bin/cajeer ops:check
```

4. Запустите postcheck из [deployment-recovery-1.8.7](deployment-recovery-1.8.7.md), убедитесь в успешном реальном GET и авторизованной операции, наличии медиа и корректном состоянии фоновой очереди. Восстановление БД не гарантирует, что внешние AI или webhook side effects можно безопасно повторить.
5. Лишь после устранения pending restore marker, одобрения владельца данных и проверки остальных сервисов разрешается:

```bash
php bin/cajeer maintenance:off
```

При любой неудаче сохраняйте maintenance и исходный backup; не удаляйте active/restore marker вручную. Не пытайтесь использовать `update:rollback --yes` как универсальный DR: это возврат к snapshot апдейтера и может потерять все последующие записи. Отдельные шаги для провалившейся транзакции описаны в [operations-deployment](operations-deployment-1.8.8.md).

## RTO, RPO, и политика хранения

Фиксируйте время последней подтверждённой записи, захваченной в backup (`RPO`), и время от начала недоступности сервиса до полной проверки реального HTTP, admin и worker (`RTO`). Измерение `backup:drill` не покрывает DNS, TLS, provision, внешние хранилища, очереди и пользовательскую проверку. Введите минимум два независимых сохранённых исправных backup, но конкретную частоту, retention и PITR определяйте бизнес-требованиями и возможностями БД; встроенный механизм не заменяет WAL/PITR. Следуйте [disaster-recovery](disaster-recovery.md) и [update-backup](update-backup.md).
