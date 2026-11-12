<?php

declare(strict_types=1);
namespace CajeerEngine\Database;

/** An unavailable production database must never silently become a second data store. */
final class StorageMode
{
    public static function resolve(string $variable): string
    {
        $mode=strtolower(trim((string)(getenv($variable)?:'auto')));
        if(!in_array($mode,['auto','file','database'],true))throw new \InvalidArgumentException($variable.' must be auto, file or database');
        if($mode==='auto'&&strtolower((string)(getenv('APP_ENV')?:'production'))==='production')return 'database';
        return $mode;
    }
}
