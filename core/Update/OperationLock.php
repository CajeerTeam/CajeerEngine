<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

/** Shared application activity lease; exclusive backup/update drains in-flight PHP/CLI work. */
final class OperationLock
{
    private static array $exclusive = [];
    public static function exclusiveHeld(string $root): bool { $h=self::$exclusive[realpath($root)?:$root]??null; return is_resource($h); }
    /** Open an existing or newly created regular lock without following a symlink.
     * A stream descriptor and its directory entry must identify the same inode.
     * Never chmod an existing lock (which could be somebody else's file).
     * @return resource
     */
    public static function openLockFile(string $path, int $mode = 0660): mixed
    {
        OperationFiles::dir(dirname($path));
        if (is_link($path)) throw new \RuntimeException('Unsafe lock path');
        $handle = @fopen($path, 'x+');
        if (is_resource($handle)) {
            try {
                if (!chmod($path, $mode)) throw new \RuntimeException('Cannot protect new lock');
            } catch (\Throwable $e) { fclose($handle); throw $e; }
        } else {
            // x+ may fail because another process has created the entry. Recheck
            // its type with lstat before opening it; the inode check follows below.
            clearstatcache(true, $path);
            $existing = @lstat($path);
            if (!is_array($existing) || ($existing['mode'] & 0170000) !== 0100000) {
                throw new \RuntimeException('Unsafe lock path');
            }
            $handle = @fopen($path, 'r+');
            if (!is_resource($handle)) throw new \RuntimeException('Cannot access lock');
        }
        clearstatcache(true, $path);
        $entry = @lstat($path);
        $descriptor = fstat($handle);
        if (!is_array($entry) || !is_array($descriptor) ||
            ($entry['mode'] & 0170000) !== 0100000 ||
            ($descriptor['mode'] & 0170000) !== 0100000 ||
            $entry['ino'] !== $descriptor['ino'] || $entry['dev'] !== $descriptor['dev'] ||
            $entry['nlink'] !== 1 || $descriptor['nlink'] !== 1 ||
            ($entry['mode'] & 0002) !== 0) {
            fclose($handle);
            throw new \RuntimeException('Unsafe lock identity or permissions');
        }
        return $handle;
    }

    public static function acquire(string $root, bool $exclusive, bool $wait = false): mixed
    {
        $path = OperationFiles::path($root, 'storage/app/operations.lock');
        OperationFiles::dir(dirname($path), 0775);
        $handle = self::openLockFile($path, 0660);
        if (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | ($wait ? 0 : LOCK_NB))) { fclose($handle); throw new \RuntimeException('Another operation is running; retry after drain'); }
        if ($exclusive) self::$exclusive[realpath($root)?:$root]=$handle;
        return $handle;
    }
    /** Retain the old child lease through recovery. An orphaned subprocess holds it via util-linux flock. */
    public static function childGuard(string $path): mixed
    {
        $h = self::openLockFile($path, 0600);
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); throw new \RuntimeException('An update subprocess is still running; wait for it to exit before recovery'); }
        return $h;
    }
    public static function release(mixed $handle): void { if (is_resource($handle)) { foreach(self::$exclusive as $key=>$held) if($held===$handle) unset(self::$exclusive[$key]); flock($handle, LOCK_UN); fclose($handle); } }
    public static function active(string $root): bool
    {
        $path = OperationFiles::path($root, 'storage/app/updates/active.json');
        return is_file($path);
    }
    public static function maintenance(string $root): bool { return is_file(OperationFiles::path($root,'storage/app/extensions/deployment.json')) || self::active($root) || is_file(OperationFiles::path($root, 'storage/app/backup-restore.json')) || is_file(OperationFiles::path($root, 'storage/app/maintenance.json')); }
}
