# Migrations

Миграции лежат в `migrations/mysql` и `migrations/pgsql`.

```bash
php bin/cajeer migrate --database=mysql
php bin/cajeer migrate:status --database=pgsql
```

MigrationRunner хранит batch, checksum, driver и дату выполнения.
