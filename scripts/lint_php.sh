#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
files=(
  "public/index.php"
  "bin/cajeer"
  "core/bootstrap.php"
  "core/Kernel/Application.php"
  "core/Database/DatabaseManager.php"
  "core/Database/MigrationRunner.php"
  "routes/web.php"
  "routes/admin.php"
  "routes/api.php"
  "modules/Installer/Http/InstallController.php"
  "modules/Auth/SessionGuard.php"
  "modules/Content/Repository/ContentRepository.php"
  "modules/Marketplace/PackageInstaller.php"
)
for file in "${files[@]}"; do
  php -n -l "$ROOT/$file" >/dev/null || { echo "PHP lint failed: $file" >&2; exit 1; }
done
echo "PHP lint: ok"
