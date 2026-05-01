#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

mkdir -p "$ROOT/storage/cache" "$ROOT/storage/logs" "$ROOT/storage/compiled_tpl" "$ROOT/storage/sessions" "$ROOT/storage/uploads" "$ROOT/public/uploads"
chown -R "$WEB_USER:$WEB_GROUP" "$ROOT/storage" "$ROOT/public/uploads" || true
chmod -R 775 "$ROOT/storage" "$ROOT/public/uploads"

echo "Права storage/public/uploads подготовлены."
