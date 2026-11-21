#!/usr/bin/env bash
# Test a real Composer-equipped dist, signed newer fixture, migrations, recovery and rollback.
set -euo pipefail
phase=initialization
# Do not echo shell commands: they can contain credentials.
trap 'rc=$?; printf "Update dist smoke failed at %s (exit=%s)\n" "$phase" "$rc" >&2' ERR
artifact=$(realpath "${1:?physical dist ZIP required}")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
unzip -q "$artifact" -d "$work/live"
root="$work/live/cajeerengine"
cd "$root"
phase=install
php bin/cajeer install --sqlite --production --domain=localhost --admin-email=update-smoke@example.invalid --admin-password='UpdateSmokeOnly-9X!' --no-interaction --json >/dev/null
# Avoid requiring an external Redis server in this disposable SQLite smoke.
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=file/' .env
php bin/cajeer backup:keygen --key="$work/backup.key" >/dev/null
php bin/cajeer release:keygen --secret-key="$work/secret.key" --public-key="$work/public.key" >/dev/null
export BACKUP_KEY_FILE="$work/backup.key" UPDATE_TRUSTED_PUBLIC_KEY_FILE="$work/public.key"
phase=backup
php bin/cajeer backup:create > "$work/backup.json"
id=$(php -r 'echo json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["id"];' "$work/backup.json")
php bin/cajeer backup:verify --id="$id" >/dev/null
old=$(cat VERSION)
new=$(php -r '$p=explode(".",$argv[1]); echo $p[0].".".$p[1].".".((int)$p[2]+1);' "$old")
phase=prepare-fixture
unzip -q "$artifact" -d "$work/target"
target="$work/target/cajeerengine"
printf '%s\n' "$new" > "$target/VERSION"
# A synthetic patch release must keep every embedded version in sync: the
# artifact verifier rejects stale OpenAPI contracts just as it rejects stale
# release/admin metadata. No production code or dependency is changed here.
php -r '
$root=$argv[1];$version=$argv[2];
foreach (["release.json","public/admin/assets/admin-manifest.json"] as $p) {
    $file=$root."/".$p;
    $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $data["version"]=$version;
    file_put_contents($file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}
$file=$root."/api/openapi.yaml";
$contract=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if (!is_array($contract["info"]??null)) throw new RuntimeException("Missing embedded OpenAPI info");
$contract["info"]["version"]=$version;
file_put_contents($file,json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
' "$target" "$new"
# Operational runbooks are versioned release metadata too. The production
# Doctor requires their titles to match VERSION; keep the synthetic fixture
# consistent without changing the actual 2.0.0 runbooks.
php -r '
$root=$argv[1];$old=$argv[2];$new=$argv[3];
$files=glob($root."/docs/operations-*.md") ?: [];
if (count($files)<7) throw new RuntimeException("Missing operational runbooks in update fixture");
foreach ($files as $file) {
    $content=file_get_contents($file);
    $prefix="# CajeerEngine ".$old." ";
    if (!str_starts_with($content,$prefix)) throw new RuntimeException("Unexpected runbook title: ".basename($file));
    file_put_contents($file,"# CajeerEngine ".$new.substr($content,strlen("# CajeerEngine ".$old)));
}
' "$target" "$old" "$new"
# Only synthetic release metadata and a test migration differ; production dependencies remain genuine.
printf 'CREATE TABLE ce_update_smoke (id INTEGER PRIMARY KEY); INSERT INTO ce_update_smoke VALUES (1);\n' > "$target/migrations/sqlite/9999_update_smoke.sql"
phase=build-fixture
(cd "$target"; php tools/build-release.php --dist --target-dir="$work/artifacts" --json >/dev/null)
package="$work/artifacts/cajeerengine-$new-dist.zip"
php bin/cajeer release:sign --package="$package" --secret-key="$work/secret.key" >/dev/null
phase=dry-run
php bin/cajeer update:apply --package="$package" --dry-run >/dev/null
[[ $(cat VERSION) == "$old" ]]
phase=apply-update
# Capture a bounded and scrubbed reason rather than silently discarding the updater report.
if ! php bin/cajeer update:apply --package="$package" --json > "$work/update.json"; then
  php -r '$j=json_decode((string)@file_get_contents($argv[1]),true);
  $state=is_array($j)?($j["state"]??"unknown"):"unavailable";
  $error=is_array($j)?($j["error"]??"missing updater error"):"invalid updater report";
  $clean=static fn($s)=>substr(preg_replace("/[^a-zA-Z0-9 _.:()\\/-]/","?",(string)$s)??"unavailable",0,240);
  fwrite(STDERR,"Update failed: state=".$clean($state)." reason=".$clean($error)."\n");' "$work/update.json"
  exit 1
fi
phase=verify-updated-version
[[ $(cat VERSION) == "$new" ]]
php -r '$p=new PDO("sqlite:storage/database/cajeer.sqlite"); if ($p->query("SELECT count(*) FROM ce_update_smoke")->fetchColumn()!=1) exit(1);'
phase=rollback
php bin/cajeer update:rollback --yes >/dev/null
[[ $(cat VERSION) == "$old" ]]
php -r '$p=new PDO("sqlite:storage/database/cajeer.sqlite"); if ($p->query("SELECT count(*) FROM sqlite_master WHERE name=\x27ce_update_smoke\x27")->fetchColumn()!=0) exit(1);'
# A failing SQL migration must recover the actual pre-update database and release.
printf 'CREATE TABLE ce_update_smoke (id INTEGER PRIMARY KEY); THIS IS INVALID SQL;\n' > "$target/migrations/sqlite/9999_update_smoke.sql"
(cd "$target"; php tools/build-release.php --dist --target-dir="$work/broken" --json >/dev/null)
broken="$work/broken/cajeerengine-$new-dist.zip"
php bin/cajeer release:sign --package="$broken" --secret-key="$work/secret.key" >/dev/null
phase=reject-broken-migration
if php bin/cajeer update:apply --package="$broken" --json > "$work/failed.json"; then echo 'Invalid migration unexpectedly committed' >&2; exit 1; fi
php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($j["state"]??"")!=="rolled_back") exit(1);' "$work/failed.json"
[[ $(cat VERSION) == "$old" && ! -e storage/app/maintenance.json && ! -e storage/app/updates/active.json ]]
php tools/update-postcheck.php verify
printf 'Actual dist update, rollback and failed migration recovery passed\n'
