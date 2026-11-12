#!/usr/bin/env bash
# Installs a physical dist in a disposable directory and exercises HTTP + DB.
set -Eeuo pipefail
# Do not enable xtrace: headers, cookies and database credentials are secret.
artifact=$(realpath "${1:?dist ZIP required}")
driver=${2:-sqlite}
port=${CE_SMOKE_PORT:-18764}
root=$(mktemp -d)
pid=''
phase=extract
fail_line=''
trap 'rc=$?; fail_line=$LINENO; printf "Smoke failure: phase=%s line=%s exit=%s\n" "$phase" "$fail_line" "$rc" >&2' ERR
cleanup() {
  local rc=$?
  trap - ERR EXIT
  if (( rc != 0 )); then
    printf 'Dist smoke failed (%s), phase=%s, line=%s, exit=%s\n' "$driver" "$phase" "${fail_line:-unknown}" "$rc" >&2
    # Only print booleans/check names, never installer JSON, cookies or secrets.
    if [[ -f "$root/preflight.json" ]]; then
      php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j))exit;echo "preflight_ready=".json_encode($j["ready"]??null).PHP_EOL;foreach($j["checks"]??[] as $k=>$v)if(($v["ok"]??false)!==true&&!in_array($k,["env_file","app_key"],true))echo "preflight_failed: ".$k.PHP_EOL;' "$root/preflight.json" >&2 || true
    fi
    for report in "$root/install.json" "$root/cajeerengine/storage/app/install-state.json"; do
      if [[ -s $report ]]; then
        php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j))exit;$f=$j["final"]??[];echo "install_ok=".json_encode($j["ok"]??null).PHP_EOL;foreach(["database","routing","security_doctor","admin_assets"] as $k)if(isset($f[$k]))echo $k."_ok=".json_encode($f[$k]["ok"]??null).PHP_EOL;foreach(["pending","failed"] as $k)if(isset($f["migrations"][$k]))echo "migrations_".$k."=".(int)$f["migrations"][$k].PHP_EOL;' "$report" >&2 || true
      fi
    done
    if [[ -f "$root/http.log" ]]; then
      # Access log only: no headers, query strings, response bodies or stack traces.
      sed -nE 's/^.*(\[[1-5][0-9]{2}\]: (GET|HEAD|POST|PATCH|PUT|DELETE) [^ ?]+).*$/\1/p' "$root/http.log" | tail -n 30 >&2 || true
    fi
  fi
  if [[ -n $pid ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -rf "$root"
  exit "$rc"
}
trap cleanup EXIT
stage() { phase=$1; printf 'Smoke (%s): %s\n' "$driver" "$phase"; }
unzip -q "$artifact" -d "$root"
cd "$root/cajeerengine"
[[ ! -e .env && ! -e storage/app/installed.lock && ! -d admin && -f vendor/autoload.php ]]
php bin/cajeer release:verify --dist --strict --json
php bin/cajeer admin:assets --check
stage preflight
php bin/cajeer install:check --db="$driver" --json > "$root/preflight.json"
password='ReleaseSmokeOnly-9X!'
args=(--domain="127.0.0.1:$port" --admin-email=smoke@example.invalid --admin-password="$password" --no-interaction --json)
if [[ $driver == sqlite ]]; then
  args+=(--sqlite)
elif [[ $driver == pgsql ]]; then
  args+=(--db=pgsql --production --db-host="${CE_DB_HOST:-127.0.0.1}" --db-port="${CE_DB_PORT:-5432}" --db-name="${CE_DB_NAME:?disposable postgres database required}" --db-user="${CE_DB_USER:?}" --db-password="${CE_DB_PASSWORD:?}")
else echo 'Unsupported smoke driver' >&2; exit 1; fi
# Negative preflight: damaged admin must not modify the DB or install lock.
stage reject-damaged-admin
mv public/admin/assets/admin-app.js public/admin/assets/admin-app.js.saved
if php bin/cajeer install "${args[@]}" > "$root/bad-install.log" 2>&1; then echo 'Broken assets accepted' >&2; exit 1; fi
[[ ! -e .env && ! -e storage/app/installed.lock ]]
mv public/admin/assets/admin-app.js.saved public/admin/assets/admin-app.js
stage install
php bin/cajeer install "${args[@]}" > "$root/install.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($j["ok"]??false)!==true||($j["final"]["ok"]??false)!==true) exit(1);' "$root/install.json"
[[ -s storage/app/installed.lock ]]
stage cms-bootstrap
export CONTENT_STORAGE=database CMS_STORAGE=database
php bin/cajeer cms:bootstrap --json >/dev/null
# Exercise the installed site's actual dotenv configuration, not an inherited
# shell variable which Dotenv can overwrite when it is absent from PHP superglobals.
unset OBSERVABILITY_METRICS_TOKEN
php -r 'require "vendor/autoload.php"; (new \CajeerEngine\Support\EnvironmentFile(".env"))->setMany(["OBSERVABILITY_METRICS_TOKEN"=>""]);'
metrics_token="$(php -r 'echo bin2hex(random_bytes(32));')"
php -S "127.0.0.1:$port" -t public > "$root/http.log" 2>&1 &
pid=$!
base="http://127.0.0.1:$port"
stage health-and-metrics
ready=0
for i in $(seq 1 40); do if curl -fsS "$base/api/v1/health" >/dev/null 2>&1; then ready=1; break; fi; sleep 0.25; done
[[ $ready == 1 ]]
status() { curl -sS -o "$root/response.json" -w '%{http_code}' "$@"; }
expect_status() {
  local expected=$1 actual url
  shift
  url=${@: -1}
  actual=$(status "$@")
  if [[ $actual != "$expected" ]]; then
    printf 'HTTP status mismatch: %s expected=%s actual=%s\n' "${url%%\?*}" "$expected" "$actual" >&2
    return 1
  fi
}
curl -fsS "$base/health/live" | grep -F alive >/dev/null
curl -fsS "$base/health/ready" | grep -F ready >/dev/null
# Metrics are deliberately disabled until an operator configures a dedicated token.
expect_status 404 "$base/metrics"
CE_SMOKE_METRICS_TOKEN="$metrics_token" php -r '
require "vendor/autoload.php";
$token=getenv("CE_SMOKE_METRICS_TOKEN");
if(!is_string($token)||strlen($token)!==64)throw new RuntimeException("Invalid smoke metrics token");
$env=new \CajeerEngine\Support\EnvironmentFile(".env");
$env->setMany(["OBSERVABILITY_METRICS_TOKEN"=>$token]);
if($env->get("OBSERVABILITY_METRICS_TOKEN")!==$token)throw new RuntimeException("Cannot configure smoke metrics token");
'
expect_status 401 "$base/metrics"
expect_status 401 -H "Authorization: Bearer wrong-token" "$base/metrics"
curl -fsS -H "Authorization: Bearer $metrics_token" "$base/metrics" | grep -F 'cajeer_ready 1' >/dev/null
unset metrics_token
curl -fsS "$base/admin/" | grep -F 'admin-app.js' >/dev/null
curl -fsS "$base/admin/assets/admin-app.js" >/dev/null
expect_status 403 "$base/install/"
stage admin-login
login=$(curl -fsS -H 'Content-Type: application/json' -d '{"email":"smoke@example.invalid","password":"ReleaseSmokeOnly-9X!"}' "$base/api/v1/auth/login")
token=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["access_token"] ?? "";' <<< "$login")
[[ -n $token ]]
auth=(-H "Authorization: Bearer $token" -H 'Content-Type: application/json')
curl -fsS "${auth[@]}" "$base/api/v1/admin/dashboard" >/dev/null
curl -fsS "${auth[@]}" "$base/api/v1/system/operations" | php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if(!is_array($j["readiness"]??null))exit(1);'
expect_status 401 "$base/api/v1/system/operations"
stage content-publication
entry=$(curl -fsS "${auth[@]}" -d '{"title":"Release smoke content","slug":"release-smoke","status":"draft","locale":"ru","data":{"heading":"Release smoke content","path":"/release-smoke","body":"<p>Verified installation</p>"}}' "$base/api/v1/content/pages")
id=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["id"]??"";' <<< "$entry")
[[ -n $id ]]
curl -fsS "${auth[@]}" -X POST "$base/api/v1/content/pages/$id/publish" >/dev/null
curl -fsS "$base/release-smoke" | grep -F 'Release smoke content' >/dev/null
# Verify SQL-indexed paths, nested moves, historical aliases and publication privacy.
stage nested-routes
child=$(curl -fsS "${auth[@]}" -d "{\"title\":\"Nested release child\",\"slug\":\"child\",\"status\":\"published\",\"locale\":\"ru\",\"data\":{\"parent_id\":\"$id\",\"heading\":\"Nested release child\"}}" "$base/api/v1/content/pages")
childid=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["id"];' <<< "$child")
curl -fsS "$base/release-smoke/child" | grep -F 'Nested release child' >/dev/null
curl -fsS "${auth[@]}" -X PATCH -d '{"data":{"path":"/release-moved"}}' "$base/api/v1/content/pages/$id" >/dev/null
expect_status 301 "$base/release-smoke/child"
curl -fsS "$base/release-moved/child" | grep -F 'Nested release child' >/dev/null
expect_status 308 "$base/release-moved/child/"
# Decode JSON rather than matching its escaped serialization.
curl -fsS "${auth[@]}" "$base/api/v1/cms/routes" | php -r '
$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
foreach($j["data"]??[] as $route){
    if(($route["kind"]??null)==="page"&&($route["path"]??null)==="/release-moved/child"&&($route["id"]??null)===$argv[1])exit(0);
}
fwrite(STDERR,"Canonical child route not found in decoded route list".PHP_EOL);exit(1);
' "$childid"
# Public search uses the same publication generation and canonical URL as routing.
stage search-and-publication-privacy
curl -fsS "$base/search?q=Nested" | grep -F '/release-moved/child' >/dev/null
curl -fsS "$base/api/v1/public/search?q=Nested&locale=ru&type=pages" | php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); if($j["meta"]["total"]!==1||$j["data"][0]["url"]!=="/release-moved/child")exit(1);'
expect_status 401 "$base/api/v1/search?q=Nested"
expect_status 422 "$base/api/v1/public/search?q=Nested&limit=101"

curl -fsS "${auth[@]}" -X POST "$base/api/v1/content/pages/$id/unpublish" >/dev/null
expect_status 404 "$base/release-moved/child"
curl -fsS "$base/api/v1/public/search?q=Nested" | php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if($j["meta"]["total"]!==0)exit(1);'

curl -fsS "${auth[@]}" -X POST "$base/api/v1/content/pages/$id/publish" >/dev/null
curl -fsS "${auth[@]}" -X PATCH -d '{"data":{"path":"/release-smoke"}}' "$base/api/v1/content/pages/$id" >/dev/null
curl -fsS "$base/release-smoke/child" | grep -F 'Nested release child' >/dev/null
curl -fsS "$base/api/v1/public/search?q=Nested" | php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if($j["data"][0]["url"]!=="/release-smoke/child")exit(1);'
php bin/cajeer search:check --json >/dev/null
php bin/cajeer routing:check --json >/dev/null
# Auth, RBAC and CSRF regression against actual HTTP/DB runtime.
stage auth-rbac-csrf
expect_status 401 "$base/api/v1/users"
expect_status 401 --path-as-is "$base/api/v1/%75sers"
expect_status 401 -I "$base/api/v1/users"
expect_status 422 "${auth[@]}" -d '{"email":"bad"}' "$base/api/v1/auth/login"
expect_status 422 "${auth[@]}" -d '{"force":true,"package":"test.zip"}' "$base/api/v1/updates/apply"
viewer=$(curl -fsS "${auth[@]}" -d '{"email":"viewer@example.invalid","name":"Viewer","password":"ReleaseSmokeOnly-9X!","roles":["viewer"]}' "$base/api/v1/users")
viewerid=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["id"];' <<< "$viewer")
viewerlogin=$(curl -fsS -H 'Content-Type: application/json' -d '{"email":"viewer@example.invalid","password":"ReleaseSmokeOnly-9X!"}' "$base/api/v1/auth/login")
viewertoken=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["access_token"];' <<< "$viewerlogin")
expect_status 403 -H "Authorization: Bearer $viewertoken" -H 'Content-Type: application/json' -d '{"title":"denied","data":{}}' "$base/api/v1/content/pages"
expect_status 200 "${auth[@]}" -X DELETE "$base/api/v1/users/$viewerid"
expect_status 401 -H "Authorization: Bearer $viewertoken" "$base/api/v1/auth/me"
browser=$(curl -fsS -c "$root/cookies" -H 'X-Cajeer-Browser: 1' -H 'Content-Type: application/json' -d '{"email":"smoke@example.invalid","password":"ReleaseSmokeOnly-9X!"}' "$base/api/v1/auth/login")
csrf=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); if(isset($j["data"]["access_token"]))exit(1); echo $j["data"]["csrf_token"];' <<< "$browser")
[[ -n $csrf ]]
expect_status 200 -b "$root/cookies" "$base/api/v1/auth/me"
expect_status 403 -b "$root/cookies" -X POST "$base/api/v1/auth/logout"
expect_status 200 -b "$root/cookies" -H "X-CSRF-Token: $csrf" -X POST "$base/api/v1/auth/logout"
expect_status 401 -b "$root/cookies" "$base/api/v1/auth/me"
expect_status 422 "${auth[@]}" -d '{"filename":"fake.png","mime_type":"image/png","content_base64":"PGh0bWw+YmFkPC9odG1sPg=="}' "$base/api/v1/media"
stage maintenance
expect_status 201 "${auth[@]}" -X POST "$base/api/v1/updates/maintenance/on"
expect_status 503 "$base/release-smoke"
expect_status 200 "${auth[@]}" -X POST "$base/api/v1/updates/maintenance/off"
expect_status 200 "$base/release-smoke"
echo "Dist smoke passed ($driver)"
