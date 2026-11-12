#!/usr/bin/env bash
# Installs a physical dist in a disposable directory and exercises HTTP + DB.
set -euo pipefail
artifact=$(realpath "${1:?dist ZIP required}")
driver=${2:-sqlite}
port=${CE_SMOKE_PORT:-18764}
root=$(mktemp -d)
pid=''
trap 'if [[ -n $pid ]]; then kill "$pid" 2>/dev/null || true; fi; rm -rf "$root"' EXIT
unzip -q "$artifact" -d "$root"
cd "$root/cajeerengine"
[[ ! -e .env && ! -e storage/app/installed.lock && ! -d admin && -f vendor/autoload.php ]]
php bin/cajeer release:verify --dist --strict --json
php bin/cajeer admin:assets --check
php bin/cajeer install:check --db="$driver" --json > "$root/preflight.json"
password='ReleaseSmokeOnly-9X!'
args=(--domain="127.0.0.1:$port" --admin-email=smoke@example.invalid --admin-password="$password" --no-interaction --json)
if [[ $driver == sqlite ]]; then
  args+=(--sqlite)
elif [[ $driver == pgsql ]]; then
  args+=(--db=pgsql --production --db-host="${CE_DB_HOST:-127.0.0.1}" --db-port="${CE_DB_PORT:-5432}" --db-name="${CE_DB_NAME:?disposable postgres database required}" --db-user="${CE_DB_USER:?}" --db-password="${CE_DB_PASSWORD:?}")
else echo 'Unsupported smoke driver' >&2; exit 1; fi
# Negative preflight: damaged admin must not modify the DB or install lock.
mv public/admin/assets/admin-app.js public/admin/assets/admin-app.js.saved
if php bin/cajeer install "${args[@]}" > "$root/bad-install.log" 2>&1; then echo 'Broken assets accepted' >&2; exit 1; fi
[[ ! -e .env && ! -e storage/app/installed.lock ]]
mv public/admin/assets/admin-app.js.saved public/admin/assets/admin-app.js
php bin/cajeer install "${args[@]}" > "$root/install.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($j["ok"]??false)!==true||($j["final"]["ok"]??false)!==true) exit(1);' "$root/install.json"
[[ -s storage/app/installed.lock ]]
export CONTENT_STORAGE=database CMS_STORAGE=database
php bin/cajeer cms:bootstrap --json >/dev/null
php -S "127.0.0.1:$port" -t public > "$root/http.log" 2>&1 &
pid=$!
base="http://127.0.0.1:$port"
for i in $(seq 1 40); do if curl -fsS "$base/api/v1/health" >/dev/null 2>&1; then break; fi; sleep 0.25; done
curl -fsS "$base/admin/" | grep -q 'admin-app.js'
curl -fsS "$base/admin/assets/admin-app.js" >/dev/null
[[ $(curl -s -o /dev/null -w '%{http_code}' "$base/install/") == 403 ]]
login=$(curl -fsS -H 'Content-Type: application/json' -d '{"email":"smoke@example.invalid","password":"ReleaseSmokeOnly-9X!"}' "$base/api/v1/auth/login")
token=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["access_token"] ?? "";' <<< "$login")
[[ -n $token ]]
auth=(-H "Authorization: Bearer $token" -H 'Content-Type: application/json')
curl -fsS "${auth[@]}" "$base/api/v1/admin/dashboard" >/dev/null
entry=$(curl -fsS "${auth[@]}" -d '{"title":"Release smoke content","slug":"release-smoke","status":"draft","locale":"ru","data":{"heading":"Release smoke content","path":"/release-smoke","body":"<p>Verified installation</p>"}}' "$base/api/v1/content/pages")
id=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["id"]??"";' <<< "$entry")
[[ -n $id ]]
curl -fsS "${auth[@]}" -X POST "$base/api/v1/content/pages/$id/publish" >/dev/null
curl -fsS "$base/release-smoke" | grep -q 'Release smoke content'
echo "Dist smoke passed ($driver)"
