#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

required=(
  "configs"
  "migrations/mysql"
  "migrations/pgsql"
  "wiki/Home.md"
  "ops/nginx/cajeer-engine.conf"
  "public/index.php"
  "storage/cache"
  "public/uploads"
  "themes/cajeer-official/extension.json"
  "public/themes/cajeer-official/assets/css/site.css"
)
for path in "${required[@]}"; do
  test -e "$ROOT/$path" || { echo "Missing: $path" >&2; exit 1; }
done
if test -d "$ROOT/docs"; then
  echo "docs/ is not allowed; use wiki/" >&2
  exit 1
fi
bash "$ROOT/scripts/lint_php.sh"
php "$ROOT/scripts/check_theme.php" cajeer-official
php "$ROOT/scripts/smoke_routes.php"
echo "Project check: ok"
