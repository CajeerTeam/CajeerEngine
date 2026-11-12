# Media Library 1.1.8

## Upgrade and storage profile

Create and verify a full backup before the upgrade. Existing installations retain their JSON media catalog until explicitly migrated; new installers select SQL. Run `php bin/cajeer migrate`, then, with the existing `MEDIA_STORAGE=file` setting and the application's database configured, run `php bin/cajeer media:migrate --json`. This checks legacy originals against their recorded SHA256, inserts their records in a database transaction and writes a migration marker only after success. Set `MEDIA_STORAGE=database` in `.env` and restart workers/FPM. Run `php bin/cajeer media:check --json`. The old JSON index is retained for recovery; do not remove the migration marker or switch back to file mode. SQL failures do not fall back to JSON. Existing UUIDs and original URLs remain unchanged. Migration does not regenerate derivatives; run `media:repair` for that.

Use a single-node writable local media storage profile with backups as documented in `update-backup.md`. SQL supports PostgreSQL, MySQL/MariaDB and SQLite; the catalog has indexed folder, visibility, checksum and ordering. Text search is substring search, not a full-text engine. File mode is intended for small/demo sites. Folders are logical paths on records (not physical directories); create a folder by uploading or moving an item. Collections and tags are metadata.

## Upload and variants

The Media section supports drag-and-drop, multiple uploads, filtering, pagination, metadata edit, replacement and deletion. Content field editors can select media UUIDs. Uploads use server MIME detection and bounded streaming; `MEDIA_MAX_UPLOAD_MB` defaults to 25. Raster images are limited to 16 million pixels. Enable GD (JPEG/PNG/GIF/WebP codecs as required), or configure the absolute path `MEDIA_CONVERT_BINARY=/usr/bin/convert`. Use an up-to-date ImageMagick build with a restrictive policy and run PHP without elevated privileges. `MEDIA_REQUIRE_IMAGE_PROCESSOR=true` rejects image uploads without a processor and is set by new production installations. SVG remains disallowed by default. Variants are synchronous: thumbnail 240, small 480, medium 960, large 1600, WebP 960 (when supported), preserving aspect ratio without upscaling. GIF derivatives use the first frame. WebP input requires the relevant decoder. Originals are not modified. Configure PHP `upload_max_filesize`, `post_max_size`, memory and Nginx body limits to accommodate the selected upload limit.

Replacement requires the same MIME and extension; it retains ID and original URL, creates new checksum-versioned variants, and preserves prior variants to avoid broken public references. A replacement journal supports recovery if interrupted. Usage checks cover current entries, revisions, menu and theme data; arbitrary extension data and custom templates are not automatically discoverable. Deletion of referenced files is refused. GC reports untracked paths but does not delete them. Retain backups before removing files outside the application.

## Private delivery

Choose visibility at upload. Private originals and derivatives live in `storage/app/media-private`, never in the webroot. Private media does not appear in the public template media collection. Private downloads use authenticated API endpoints or expiring URLs signed with APP_KEY. Local signed URLs bind to the ID, checksum, variant and expiry; replacing an original invalidates prior signed URLs. The authenticated API can preview raster derivatives; other downloads are attachments. Do not publish private original links as a substitute for authorization. Keep the document root `public/` and never serve `storage/`.

S3 is an optional **private replica**, not primary storage. Configure `S3_ENDPOINT`, `S3_BUCKET`, `S3_REGION`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_PATH_STYLE`; use HTTPS and a private bucket. The credential needs object write/read access. `media:s3-sync` streams the original to a checksum-versioned object and verifies the returned bytes before enabling delivery. S3 signed GET URLs are temporary and cannot be revoked by replacing a local file before their expiry. Replica retention/removal is an operator bucket lifecycle policy; local deletion does not remove remote objects. Backups and import/export use authoritative local originals. No S3-only mode or distributed filesystem locking is claimed.

## CLI

```sh
php bin/cajeer media:list --json
php bin/cajeer media:check --json
php bin/cajeer media:variants --id=UUID --json
php bin/cajeer media:usage --id=UUID --json
php bin/cajeer media:repair --json
php bin/cajeer media:recover --json
php bin/cajeer media:s3-sync --id=UUID --json
php bin/cajeer media:gc --dry-run --json
```

`media:check` checks SHA256 for originals and derivatives and reports missing, damaged and untracked files. Repair recovers a pending replacement then regenerates derivatives; it cannot reconstruct a missing/damaged original, which must be restored from backup. CLI mutations use the existing application operation lock.

Portable ZIPs retain visibility, logical folders and metadata; binary originals are exported once per checksum. Deduplication is visibility-aware. Import regenerates derivatives locally and rewrites portable derivative URL references to the mapped original. Replica credentials and signed URLs are not transported as delivery state. Exports containing private originals are sensitive and must be protected.

## Regression

`php tools/media-regression.php` exercises real binary images, visibility, signed URLs, replacement recovery, usage protection and portable import with an actual SQLite backup. `php tools/media-regression.php --database` additionally exercises SQL catalog migration and database operations with PDO SQLite. Run release CI on the supported production stack before deployment.
