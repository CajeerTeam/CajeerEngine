# CajeerEngine 2.0.3 — Installation, Upgrade & Rollback Runbook

## 1. Требования до начала работ

Проверяемый профиль: single-node Linux/systemd + Nginx TLS + PHP-FPM 8.4 + PostgreSQL 17+; PHP CLI и FPM должны использовать совместимые расширения. Подготовьте выделенного владельца runtime-каталогов и отдельного привилегированного оператора релиза. Проверьте доступность места для двух копий managed-файлов, временного распакованного dist, дампа БД и внешнего encrypted backup. Уточните окно обслуживания, допустимый простой и план вывода трафика. Если используются Redis/S3/внешняя очередь, обеспечьте консистентный snapshot вне CMS.

Ни одна из операций ниже не должна выполняться вслепую на production. Убедитесь в наличии реального подписанного `cajeerengine-1.8.8-dist.zip`, подписи `.sig` и ожидаемого SHA-256 из доверенного релизного канала. Версия в примере — цель обновления, не подтверждение наличия опубликованного артефакта.

## 2. Проверка до обновления

```bash
php bin/cajeer maintenance:status
php bin/cajeer doctor --json --no-report
php bin/cajeer ops:check
php bin/cajeer migrate:status
php bin/cajeer update:status
php bin/cajeer backup:list
```

Убедитесь, что нет активного `storage/app/updates/active.json`, неисполненных запросов администратора, сломанного migration integrity и незавершённого restore marker. Сохраните version/sha, очередь и состояние фоновых задач. Если уже есть активная транзакция, сначала примените [runbook инцидента](operations-incidents-1.8.8.md), а не запускайте вторую. Для подтверждения актуального состояния админки проверьте /admin/ и вход через браузер в staging; `health/live` сам по себе недостаточен.

## 3. Резервная копия и maintenance

Подготовьте отдельный off-host export по [DR runbook](operations-recovery-1.8.8.md), проверьте его на изолированном стенде до выполнения необратимых шагов. Остановите *всех* пишущих внешних клиентов, worker и scheduler через менеджер служб. Не удаляйте runtime-файлы очереди, lock и update journal. Включите maintenance (продолжайте только после проверки ответа 503 для публичных запросов):

```bash
php bin/cajeer maintenance:on --message=upgrade
php bin/cajeer backup:create
php bin/cajeer backup:list
```

Для PostgreSQL наличие snapshot файлов без согласованного DB-dump не гарантирует корректный restore. Для автоматического rollback механизм `update:apply` создаёт и проверяет собственный snapshot. Ручная копия отдельно нужна для независимого DR.

## 4. Подписанное обновление

Передаваемый путь — операторский файл вне docroot. Ключ доверия указывается как `UPDATE_TRUSTED_PUBLIC_KEY_FILE` в защищённой конфигурации; не отключайте `UPDATE_SIGNATURE_REQUIRED`.

```bash
php bin/cajeer update:verify-signature --package=/secure/releases/cajeerengine-1.8.8-dist.zip --sha256=APPROVED_SHA256
php bin/cajeer update:apply --package=/secure/releases/cajeerengine-1.8.8-dist.zip --dry-run
php bin/cajeer update:apply --package=/secure/releases/cajeerengine-1.8.8-dist.zip
php bin/cajeer update:status
php bin/cajeer migrate:status
```

`APPROVED_SHA256` подставляется из независимого утверждённого источника, не из того же непроверенного ZIP. До закрытия maintenance проверьте права, API и админку, целостность миграций, готовность DB и storage, восстановление worker/scheduler и публичный запрос с ожидаемым содержимым. Не применяйте напрямую ZIP только с изменёнными файлами через production updater, если его подписанный manifest требует **полный** dist: для таких архивов нужен контроль исходной версии и штатный release-build/подпись.

## 5. Ошибка обновления и rollback

При сбое **не выключайте maintenance вручную**. `update:apply` пытается откатить изменения до активации side effects; при активном журнале выполните операторскую проверку, что дочерние процессы и внешние writers остановлены:

```bash
php bin/cajeer update:status
php bin/cajeer update:rollback
```

При завершённом обновлении `update:rollback --yes` **разрушительно отбрасывает новые записи БД/медиа после snapshot**. Перед таким откатом изолируйте трафик, сделайте дополнительную копию и получите явное разрешение владельца данных:

```bash
php bin/cajeer update:rollback --yes
```

Если основная CLI не запускается из-за смешанных vendor/source, допускается сохранённый launcher `storage/app/updates/transactions/<id>/recover.php` из [update-backup](update-backup.md), только после проверки журнала и ключа. Не удаляйте `active.json` и lock вручную. Запросы `update:run` после сбоя с `running` нельзя повторять: их нужно согласовать с transaction journal перед созданием нового запроса.

## 6. Контроль после работ

```bash
php bin/cajeer ops:check
php bin/cajeer ops:report
php bin/cajeer queue:diagnostics
php bin/cajeer schedule:due
php bin/cajeer maintenance:status
```

Возобновление scheduler и worker выполняйте через штатный systemd/оркестратор **после** проверки готовности; затем `maintenance:off` допустима только при отсутствии активных recovery markers и после подтверждения реального HTTP/DB/логина. Запишите время простоя, фактический RTO, версию, hash подписанного artifact, состояние очереди до/после. Для образцового root-managed контура см. [production-deployment](production-deployment.md); параметры `deploy/production.py` применяются только к его dedicated single-node profile.
