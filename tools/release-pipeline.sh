#!/usr/bin/env bash
# Run on a release host with PHP 8.4+, Composer 2, Node and ext-zip.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
if [[ ! -f composer.lock ]]; then
  echo 'Missing composer.lock. Maintainer: composer update --no-install; review and commit composer.lock.' >&2
  exit 1
fi
if [[ -n $(git status --porcelain --untracked-files=no) ]]; then
  echo 'Commit tracked changes before building a release.' >&2
  exit 1
fi
git ls-files --error-unmatch composer.lock >/dev/null
out=${1:-$root/storage/releases}
mkdir -p "$out"
out=$(cd "$out" && pwd)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
# Do not use a running installation as a build context.
git archive HEAD | tar -xf - -C "$stage"
cd "$stage"
export SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH:-$(git -C "$root" show -s --format=%ct HEAD)}
export TZ=UTC
composer validate --strict
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
composer check-platform-reqs --no-dev
node admin/scripts/publish-admin-assets.mjs
node admin/scripts/publish-admin-assets.mjs --check
php bin/cajeer admin:assets --check
php bin/cajeer release:verify --dist --strict
php tools/build-release.php --all --target-dir="$out" --json > "$out/build-report.json"
version=$(cat VERSION)
for mode in source dist; do
  php bin/cajeer release:verify --"$mode" --strict --artifact="$out/cajeerengine-$version-$mode.zip" --json
  if [[ -n ${RELEASE_SIGNING_KEY_FILE:-} ]]; then
    php bin/cajeer release:sign --package="$out/cajeerengine-$version-$mode.zip" --secret-key="$RELEASE_SIGNING_KEY_FILE"
  fi
 done
# This is a candidate until installation smoke tests have passed.
echo "Verified release artifacts in $out"
