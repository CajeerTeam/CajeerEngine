#!/usr/bin/env bash
# Full cold recovery: destroy installed dist and local backups, recover only from external bundle/key.
set -euo pipefail
artifact=$(realpath "${1:?physical dist ZIP required}")
work=$(mktemp -d)
pid=''
trap 'if [[ -n $pid ]]; then kill "$pid" 2>/dev/null || true; fi; rm -rf "$work"' EXIT
unzip -q "$artifact" -d "$work/live"
root="$work/live/cajeerengine"
cd "$root"
port=${CE_DR_SMOKE_PORT:-18767}
php bin/cajeer install --sqlite --production --domain="127.0.0.1:$port" --admin-email=dr-smoke@example.invalid --admin-password='RecoverySmokeOnly-9X!' --no-interaction --json >/dev/null
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=file/' .env
php bin/cajeer cms:bootstrap --json >/dev/null
php bin/cajeer backup:keygen --key="$work/key" >/dev/null
export BACKUP_KEY_FILE="$work/key"
php -S "127.0.0.1:$port" -t public > "$work/http.log" 2>&1 &
pid=$!
base="http://127.0.0.1:$port"
for i in $(seq 1 40); do if curl -fsS "$base/api/v1/health" >/dev/null 2>&1; then break; fi; sleep 0.25; done
login() { curl -fsS -H 'Content-Type: application/json' -d '{"email":"dr-smoke@example.invalid","password":"RecoverySmokeOnly-9X!"}' "$base/api/v1/auth/login" | php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["access_token"]??"";'; }
token=$(login); [[ -n $token ]]
auth=(-H "Authorization: Bearer $token" -H 'Content-Type: application/json')
entry=$(curl -fsS "${auth[@]}" -d '{"title":"Disaster recovery proof","slug":"dr-proof","status":"draft","locale":"ru","data":{"heading":"Disaster recovery proof","path":"/dr-proof","body":"<p>Recovered original content</p>"}}' "$base/api/v1/content/pages")
id=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["id"]??"";' <<< "$entry")
[[ -n $id ]]
curl -fsS "${auth[@]}" -X POST "$base/api/v1/content/pages/$id/publish" >/dev/null
curl -fsS "$base/dr-proof" | grep -F 'Disaster recovery proof' >/dev/null
kill "$pid"; wait "$pid" || true; pid=''
printf 'durable media bytes\n' > public/uploads/dr-proof.txt
sha256sum public/uploads/dr-proof.txt > "$work/media.sha256"
php bin/cajeer backup:create > "$work/backup.json"
backup=$(php -r 'echo json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["id"];' "$work/backup.json")
php bin/cajeer backup:export --id="$backup" --file="$work/offsite.cebackup" > "$work/export.json"
php bin/cajeer backup:drill --file="$work/offsite.cebackup" --workspace="$work/drill" --rto-seconds=300 --rpo-seconds=3600 > "$work/drill.json"
# Simulate complete loss, including .env, database, media, and local backup directory.
cd "$work"
rm -rf "$work/live"
unzip -q "$artifact" -d "$work/live"
cd "$root"
[[ ! -e .env && ! -e storage/database/cajeer.sqlite && ! -e "storage/app/backups/production/$backup" ]]
export DB_DRIVER=sqlite SQLITE_DATABASE=storage/database/cajeer.sqlite STORAGE_DRIVER=local STORAGE_LOCAL_PATH=storage/app QUEUE_DRIVER=file
php bin/cajeer backup:import --file="$work/offsite.cebackup" > "$work/import.json"
php bin/cajeer backup:restore --id="$backup" --yes > "$work/restore.json"
[[ -s .env && -s storage/app/installed.lock && -s storage/app/maintenance.json ]]
sha256sum -c "$work/media.sha256"
php tools/update-postcheck.php verify
# A protected postcheck must still reject unrelated readiness failures.
cp .env "$work/restored.env"
php -r 'require "vendor/autoload.php"; (new \CajeerEngine\Support\EnvironmentFile(".env"))->setMany(["OBSERVABILITY_MIN_FREE_BYTES"=>(string)PHP_INT_MAX]);'
if php tools/update-postcheck.php verify > "$work/negative-postcheck.log" 2>&1; then
  echo 'Protected postcheck accepted failed disk readiness' >&2; exit 1
fi
grep -F 'HTTP health smoke failed: disk' "$work/negative-postcheck.log" >/dev/null
mv "$work/restored.env" .env
# Verify public traffic is blocked until the operator reopens the site.
php -S "127.0.0.1:$port" -t public >> "$work/http.log" 2>&1 &
pid=$!
live=0
for i in $(seq 1 40); do if curl -fsS "$base/health/live" >/dev/null 2>&1; then live=1; break; fi; sleep 0.25; done
[[ $live == 1 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' "$base/health/ready") == 503 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' "$base/api/v1/health") == 503 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' "$base/dr-proof") == 503 ]]
[[ -s storage/app/maintenance.json && ! -e storage/app/backup-restore.json ]]
php bin/cajeer maintenance:off >/dev/null
curl -fsS "$base/health/ready" | grep -F '"status":"ready"' >/dev/null
curl -fsS "$base/api/v1/health" >/dev/null
php tools/update-postcheck.php verify
curl -fsS "$base/dr-proof" | grep -F 'Disaster recovery proof' >/dev/null
[[ -n $(login) ]]
echo 'Cold disaster recovery passed: encrypted offsite copy, lost host, real DB, public content, media and login'
