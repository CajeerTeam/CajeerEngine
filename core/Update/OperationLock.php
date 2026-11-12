<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

/** Shared application activity lease; exclusive backup/update drains in-flight PHP/CLI work. */
final class OperationLock
{
    private static array $exclusive = [];
    public static function exclusiveHeld(string $root): bool { $h=self::$exclusive[realpath($root)?:$root]??null; return is_resource($h); }
    public static function acquire(string $root, bool $exclusive, bool $wait = false): mixed
    {
        $path = OperationFiles::path($root, 'storage/app/operations.lock');
        OperationFiles::dir(dirname($path), 0775);
        if (!is_file($path)) { $created=@fopen($path,'x+'); if (is_resource($created)) { chmod($path,0660); fclose($created); } }
        $handle = fopen($path, $exclusive ? 'r+' : 'r');
        if ($handle === false) throw new \RuntimeException('Cannot open operation lock');
        if (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | ($wait ? 0 : LOCK_NB))) { fclose($handle); throw new \RuntimeException('Another operation is running; retry after drain'); }
        if ($exclusive) self::$exclusive[realpath($root)?:$root]=$handle;
        return $handle;
    }
    /** Retain the old child lease through recovery. An orphaned subprocess holds it via util-linux flock. */
    public static function childGuard(string $path): mixed
    {
        if (is_link($path)) throw new \RuntimeException('Invalid subprocess lease');
        $h=fopen($path,'c+');
        if ($h===false||!flock($h,LOCK_EX|LOCK_NB)) { if (is_resource($h)) fclose($h); throw new \RuntimeException('An update subprocess is still running; wait for it to exit before recovery'); }
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
