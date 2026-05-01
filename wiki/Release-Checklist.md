# Release Checklist

- `php -l` по всем PHP-файлам.
- `scripts/check.sh`.
- Smoke routes: `/`, `/install`, `/api/health`, `/marketplace`, `/admin`.
- Проверить `ops/nginx/cajeer-engine.conf`.
- Проверить отсутствие `docs/`.
- Проверить заполненность `wiki/`.
