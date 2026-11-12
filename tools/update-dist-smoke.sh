#!/usr/bin/env bash
# Test a real Composer-equipped dist, signed newer fixture, migrations, recovery and rollback.
set -euo pipefail
artifact=$(realpath "${1:?physical dist ZIP required}")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
unzip -q "$artifact" -d "$work/live"
root="$work/live/cajeerengine"
cd "$root"
php bin/cajeer install --sqlite --production --domain=localhost --admin-email=update-smoke@example.invalid --admin-password='UpdateSmokeOnly-9X!' --no-interaction --json >/dev/null
# Avoid requiring an external Redis server in this disposable SQLite smoke.
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=file/' .env
php bin/cajeer backup:keygen --key="$work/backup.key" >/dev/null
php bin/cajeer release:keygen --secret-key="$work/secret.key" --public-key="$work/public.key" >/dev/null
export BACKUP_KEY_FILE="$work/backup.key" UPDATE_TRUSTED_PUBLIC_KEY_FILE="$work/public.key"
php bin/cajeer backup:create > "$work/backup.json"
id=$(php -r 'echo json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["id"];' "$work/backup.json")
php bin/cajeer backup:verify --id="$id" >/dev/null
old=$(cat VERSION)
new=$(php -r '$p=explode(".",$argv[1]); echo $p[0].".".$p[1].".".((int)$p[2]+1);' "$old")
unzip -q "$artifact" -d "$work/target"
target="$work/target/cajeerengine"
printf '%s\n' "$new" > "$target/VERSION"
php -r 'foreach (["release.json","public/admin/assets/admin-manifest.json"] as $p) {$f=$argv[1]."/".$p;$j=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);$j["version"]=$argv[2];file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}' "$target" "$new"
# Only the version metadata and a test migration differ; all production dependencies remain genuine.
printf 'CREATE TABLE ce_update_smoke (id INTEGER PRIMARY KEY); INSERT INTO ce_update_smoke VALUES (1);\n' > "$target/migrations/sqlite/9999_update_smoke.sql"
(cd "$target"; php tools/build-release.php --dist --target-dir="$work/artifacts" --json >/dev/null)
package="$work/artifacts/cajeerengine-$new-dist.zip"
php bin/cajeer release:sign --package="$package" --secret-key="$work/secret.key" >/dev/null
php bin/cajeer update:apply --package="$package" --dry-run >/dev/null
[[ $(cat VERSION) == "$old" ]]
php bin/cajeer update:apply --package="$package" > "$work/update.json"
[[ $(cat VERSION) == "$new" ]]
php -r '$p=new PDO("sqlite:storage/database/cajeer.sqlite"); if ($p->query("SELECT count(*) FROM ce_update_smoke")->fetchColumn()!=1) exit(1);'
php bin/cajeer update:rollback --yes >/dev/null
[[ $(cat VERSION) == "$old" ]]
php -r '$p=new PDO("sqlite:storage/database/cajeer.sqlite"); if ($p->query("SELECT count(*) FROM sqlite_master WHERE name=\x27ce_update_smoke\x27")->fetchColumn()!=0) exit(1);'
# A failing SQL migration must recover the actual pre-update database and release.
printf 'CREATE TABLE ce_update_smoke (id INTEGER PRIMARY KEY); THIS IS INVALID SQL;\n' > "$target/migrations/sqlite/9999_update_smoke.sql"
(cd "$target"; php tools/build-release.php --dist --target-dir="$work/broken" --json >/dev/null)
broken="$work/broken/cajeerengine-$new-dist.zip"
php bin/cajeer release:sign --package="$broken" --secret-key="$work/secret.key" >/dev/null
if php bin/cajeer update:apply --package="$broken" > "$work/failed.json"; then echo 'Invalid migration unexpectedly committed' >&2; exit 1; fi
php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($j["state"]??"")!=="rolled_back") exit(1);' "$work/failed.json"
[[ $(cat VERSION) == "$old" && ! -e storage/app/maintenance.json && ! -e storage/app/updates/active.json ]]
php tools/update-postcheck.php verify
printf 'Actual dist update, rollback and failed migration recovery passed\n'
