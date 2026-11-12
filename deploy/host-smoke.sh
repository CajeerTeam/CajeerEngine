#!/usr/bin/env bash
# Destructive integration gate: run ONLY on a disposable Linux/systemd host as root.
set -euo pipefail
[[ $EUID == 0 && ${CE_DEPLOY_DISPOSABLE:-} == yes ]] || { echo 'Disposable root host required'; exit 1; }
artifact=$(realpath "${1:?dist ZIP required}")
source=$(cd "$(dirname "$0")/.." && pwd)
for p in /srv/cajeerengine /etc/cajeerengine-deploy /var/lib/cajeerengine-deploy /opt/ce-deploy-smoke; do [[ ! -e $p ]] || { echo "Existing path: $p"; exit 1; }; done
work=/opt/ce-deploy-smoke
mkdir -m 0755 "$work"
trap 'systemctl stop cajeerengine-scheduler.timer cajeerengine-scheduler.service cajeerengine-worker.service cajeerengine-php.service 2>/dev/null || true; if [[ ${CE_DEPLOY_KEEP:-} != yes ]]; then rm -rf /srv/cajeerengine /etc/cajeerengine-deploy /var/lib/cajeerengine-deploy "$work"; rm -f /etc/nginx/conf.d/cajeerengine.conf /etc/systemd/system/cajeerengine-{php,worker,scheduler}.service /etc/systemd/system/cajeerengine-scheduler.timer; systemctl daemon-reload; systemctl reload nginx 2>/dev/null || true; fi' EXIT
mkdir "$work/tool" "$work/inbox"
cp -a "$source/deploy" "$source/core" "$work/tool/"
chown -R root:root "$work"; chmod -R go-w "$work"
getent passwd cajeerengine >/dev/null || useradd --system --gid www-data --home-dir /var/lib/cajeerengine-runtime --shell /usr/sbin/nologin cajeerengine
mkdir -p /etc/cajeerengine-deploy/tls
chmod 0700 /etc/cajeerengine-deploy/tls
# CI test credentials are disposable only. PostgreSQL role owns only its dedicated DB.
export PGPASSWORD=${CE_DB_PASSWORD:?Postgres CI administrator password required}
psql -h 127.0.0.1 -U "${CE_DB_USER:?}" -d postgres -v ON_ERROR_STOP=1 <<'SQL'
CREATE ROLE ce_deploy_smoke LOGIN CREATEDB PASSWORD 'DeploySmokeDbPassword_92X';
CREATE DATABASE ce_deploy_smoke OWNER ce_deploy_smoke;
SQL
unset PGPASSWORD
cat >/etc/cajeerengine-deploy/site.json <<'JSON'
{"domain":"deploy.example.invalid","db_host":"127.0.0.1","db_port":5432,"db_name":"ce_deploy_smoke","db_user":"ce_deploy_smoke","db_sslmode":"disable","admin_email":"deploy@example.invalid"}
JSON
(umask 077; printf '%s\n' '{"db_password":"DeploySmokeDbPassword_92X","admin_password":"DeploySmokeAdmin-9X!"}' >/etc/cajeerengine-deploy/install-secrets.json)
openssl req -x509 -nodes -newkey rsa:2048 -days 3 -subj '/CN=deploy.example.invalid' -addext 'subjectAltName=DNS:deploy.example.invalid' -keyout /etc/cajeerengine-deploy/tls/privkey.pem -out /etc/cajeerengine-deploy/tls/fullchain.pem >/dev/null 2>&1
chmod 0600 /etc/cajeerengine-deploy/tls/privkey.pem
php "$source/bin/cajeer" release:keygen --secret-key="$work/sign.key" --public-key=/etc/cajeerengine-deploy/release.pub >/dev/null
php "$source/bin/cajeer" backup:keygen --key=/etc/cajeerengine-deploy/backup.key >/dev/null
chown cajeerengine:www-data /etc/cajeerengine-deploy/backup.key
old=$(cat "$source/VERSION")
cp "$artifact" "$work/inbox/original.zip"; chmod 0644 "$work/inbox/original.zip"
php "$source/bin/cajeer" release:sign --package="$work/inbox/original.zip" --secret-key="$work/sign.key" >/dev/null
sha=$(sha256sum "$work/inbox/original.zip" | cut -d' ' -f1)
deploy=(python3 "$work/tool/deploy/production.py")
"${deploy[@]}" deploy "$work/inbox/original.zip" --sha256="$sha" --version="$old"
http() { curl --noproxy '*' --silent --show-error --fail --resolve deploy.example.invalid:443:127.0.0.1 --cacert /etc/cajeerengine-deploy/tls/fullchain.pem "https://deploy.example.invalid$1" "${@:2}"; }
http /health/ready | grep -q 'ready'
http /admin/ | grep -q admin-app.js
# The disposable host uses a high global quota for the throughput profile; auth quota remains bounded.
# The candidate itself is unchanged; production defaults are not raised.
runuser -u cajeerengine -g www-data -- /usr/bin/php8.4 -r 'require $argv[1]."/vendor/autoload.php"; (new \CajeerEngine\Support\EnvironmentFile($argv[1]."/.env"))->setMany(["RATE_LIMIT_GLOBAL"=>"10000","RATE_LIMIT_AUTH"=>"20","RATE_LIMIT_WINDOW_SECONDS"=>"600"]);' /srv/cajeerengine/app
systemctl restart cajeerengine-php.service
for _ in $(seq 1 40); do
  if http /health/ready >/dev/null 2>&1; then break; fi
  sleep 0.25
done
http /health/ready | grep -q ready
login=$(http /api/v1/auth/login -H 'Content-Type: application/json' -d '{"email":"deploy@example.invalid","password":"DeploySmokeAdmin-9X!"}')
token=$(php -r '$j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $j["data"]["access_token"]??"";' <<<"$login")
[[ -n $token ]]
http /api/v1/admin/dashboard -H "Authorization: Bearer $token" >/dev/null
python3 "$source/tools/production-acceptance.py" --artifact "$artifact" --version "$old" \
  --ca /etc/cajeerengine-deploy/tls/fullchain.pem --domain deploy.example.invalid \
  --secrets /etc/cajeerengine-deploy/install-secrets.json --email deploy@example.invalid \
  --report "$work/production-acceptance.json"
"${deploy[@]}" deploy "$work/inbox/original.zip" --sha256="$sha" --version="$old"
# Corrupted input must not gate or change the existing website.
cp "$work/inbox/original.zip" "$work/inbox/tampered.zip"; cp "$work/inbox/original.zip.sig" "$work/inbox/tampered.zip.sig"
printf 'tamper' >> "$work/inbox/tampered.zip"
if "${deploy[@]}" deploy "$work/inbox/tampered.zip" --sha256="$sha" --version="$old"; then exit 1; fi
http /health/ready | grep -q ready
# Use real dist dependencies and a real PostgreSQL migration for update/rollback verification.
mkdir "$work/fixture"; unzip -q "$artifact" -d "$work/fixture"
fixture="$work/fixture/cajeerengine"
new=$(php -r '$v=explode(".",$argv[1]); echo $v[0].".".$v[1].".".((int)$v[2]+1);' "$old")
printf '%s\n' "$new" > "$fixture/VERSION"
php -r 'foreach (["release.json","public/admin/assets/admin-manifest.json"] as $p) {$f=$argv[1]."/".$p;$j=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);$j["version"]=$argv[2];file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}' "$fixture" "$new"
printf 'CREATE TABLE ce_deploy_probe(id INTEGER PRIMARY KEY); INVALID SQL;\n' > "$fixture/migrations/pgsql/9999_deploy_probe.sql"
(cd "$fixture"; php tools/build-release.php --dist --target-dir="$work/broken" --json >/dev/null)
broken="$work/broken/cajeerengine-$new-dist.zip"
php "$source/bin/cajeer" release:sign --package="$broken" --secret-key="$work/sign.key" >/dev/null
if "${deploy[@]}" deploy "$broken" --sha256="$(sha256sum "$broken" | cut -d' ' -f1)" --version="$new"; then echo 'Broken migration accepted'; exit 1; fi
[[ $(cat /srv/cajeerengine/app/VERSION) == "$old" ]]
http /health/ready | grep -q ready
printf 'CREATE TABLE ce_deploy_probe(id INTEGER PRIMARY KEY); INSERT INTO ce_deploy_probe VALUES (1);\n' > "$fixture/migrations/pgsql/9999_deploy_probe.sql"
(cd "$fixture"; php tools/build-release.php --dist --target-dir="$work/good" --json >/dev/null)
good="$work/good/cajeerengine-$new-dist.zip"
php "$source/bin/cajeer" release:sign --package="$good" --secret-key="$work/sign.key" >/dev/null
"${deploy[@]}" deploy "$good" --sha256="$(sha256sum "$good" | cut -d' ' -f1)" --version="$new"
[[ $(cat /srv/cajeerengine/app/VERSION) == "$new" ]]
http /api/v1/health | grep -q "\"version\":\"$new\""
http /api/v1/admin/dashboard -H "Authorization: Bearer $token" >/dev/null
PGPASSWORD=DeploySmokeDbPassword_92X psql -h 127.0.0.1 -U ce_deploy_smoke -d ce_deploy_smoke -Atc 'SELECT count(*) FROM ce_deploy_probe' | grep -qx 1
# Runtime sandbox must prevent writes to shipped code from the service namespace.
mainpid=$(systemctl show -p MainPID --value cajeerengine-php.service)
nsenter -t "$mainpid" -m -- runuser -u cajeerengine -g www-data -- test ! -w /srv/cajeerengine/app/core
[[ ! -e /var/lib/cajeerengine-deploy/maintenance ]]
# Publish evidence only after upgrade, rollback and service checks have also passed.
if [[ -n ${CE_CERT_REPORT:-} ]]; then
  install -m 0644 "$work/production-acceptance.json" "$CE_CERT_REPORT"
fi
echo 'Production host smoke passed: TLS, real FPM, PostgreSQL, worker, update and rollback'
