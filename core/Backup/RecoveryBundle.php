<?php

declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Update\OperationFiles as F;

/** Authenticated, bounded-memory, encrypted off-host transport. No archive extraction or shell. */
final class RecoveryBundle
{
    private const MAGIC = "CAJEER-DR1\n";
    private const CHUNK = 1048576;

    public static function export(string $directory, string $target, string $key): void
    {
        $tmp = dirname($target).'/.dr-'.bin2hex(random_bytes(16));
        $out = fopen($tmp, 'xb');
        if (!$out) throw new \RuntimeException('Cannot open recovery bundle');
        try {
            F::permissions($tmp,0600);
            // Domain-separate transport encryption from the existing snapshot MAC/secretbox key.
            $transportKey=sodium_crypto_generichash('cajeerengine:dr-bundle:v1',$key,32);
            try { [$state,$header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($transportKey); }
            finally { sodium_memzero($transportKey); }
            self::write($out,self::MAGIC.$header);
            $files = array_merge(['manifest.json','manifest.mac','database.dump'], is_file($directory.'/environment.enc')?['environment.enc']:[], F::files($directory,'files'));
            foreach ($files as $name) {
                $path=F::path($directory,$name); $size=filesize($path);
                if ($size===false) throw new \RuntimeException('Cannot stat recovery file');
                self::push($out,$state,json_encode(['path'=>$name,'size'=>$size],JSON_THROW_ON_ERROR));
                $in=fopen($path,'rb'); if (!$in) throw new \RuntimeException('Cannot read recovery file');
                try {
                    $remaining=$size;
                    while ($remaining>0) { $data=self::read($in,min(self::CHUNK,$remaining)); self::push($out,$state,$data); $remaining-=strlen($data); }
                    if (fread($in,1)!=='') throw new \RuntimeException('Recovery file changed during export');
                } finally { fclose($in); }
            }
            self::push($out,$state,'',true);
            if (!fflush($out)||!fsync($out)) throw new \RuntimeException('Cannot sync recovery bundle');
            fclose($out); $out=null;
            // link is atomic and refuses to overwrite an existing destination.
            if (!link($tmp,$target)) throw new \RuntimeException('Cannot publish recovery bundle (destination must not exist)');
            F::syncDir(dirname($target));
        } finally { if (isset($state)) sodium_memzero($state); if (is_resource($out)) fclose($out); if (is_file($tmp)) unlink($tmp); }
    }

    public static function extract(string $source,string $directory,string $key): void
    {
        $in=fopen($source,'rb'); if (!$in) throw new \RuntimeException('Cannot open recovery bundle');
        try {
            if (self::read($in,strlen(self::MAGIC))!==self::MAGIC) throw new \RuntimeException('Invalid recovery bundle format');
            $transportKey=sodium_crypto_generichash('cajeerengine:dr-bundle:v1',$key,32);
            try { $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($in,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),$transportKey); }
            finally { sodium_memzero($transportKey); }
            $seen=[];
            while (true) {
                [$meta,$final]=self::pull($in,$state);
                if ($final) { if ($meta!==''||fread($in,1)!=='') throw new \RuntimeException('Invalid bundle terminator'); break; }
                $entry=json_decode($meta,true,16,JSON_THROW_ON_ERROR);
                $name=$entry['path']??null; $size=$entry['size']??null;
                if (!is_string($name)||strlen($name)>4096||!is_int($size)||$size<0||isset($seen[$name])||count($seen)>=1000000||(!in_array($name,['manifest.json','manifest.mac','database.dump','environment.enc'],true)&&!str_starts_with($name,'files/'))) throw new \RuntimeException('Invalid bundle entry');
                $seen[$name]=true;
                $path=F::path($directory,$name); F::dir(dirname($path));
                $out=fopen($path,'xb'); if (!$out) throw new \RuntimeException('Cannot extract recovery file');
                try {
                    F::permissions($path,0600);
                    $remaining=$size;
                    while ($remaining>0) { [$data,$end]=self::pull($in,$state); if ($end||strlen($data)!==min(self::CHUNK,$remaining)) throw new \RuntimeException('Invalid recovery file frame'); self::write($out,$data); $remaining-=strlen($data); }
                    if (!fflush($out)||!fsync($out)) throw new \RuntimeException('Cannot sync extracted recovery file');
                } finally { fclose($out); }
                F::syncDir(dirname($path));
            }
            foreach (['manifest.json','manifest.mac','database.dump'] as $required) if (!isset($seen[$required])) throw new \RuntimeException('Incomplete recovery bundle');
        } finally { if (isset($state)) sodium_memzero($state); fclose($in); }
    }

    private static function push($out,string &$state,string $data,bool $final=false): void
    {
        $cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$data,'',$final?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        self::write($out,pack('N',strlen($cipher)).$cipher);
    }
    private static function pull($in,string &$state): array
    {
        $length=unpack('N',self::read($in,4))[1]; $overhead=SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        if ($length<$overhead||$length>self::CHUNK+$overhead) throw new \RuntimeException('Invalid recovery frame length');
        $result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::read($in,$length));
        if ($result===false||!in_array($result[1],[SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],true)) throw new \RuntimeException('Recovery bundle authentication failed');
        return [$result[0],$result[1]===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL];
    }
    private static function read($in,int $length): string
    {
        $data=''; while (strlen($data)<$length) { $part=fread($in,$length-strlen($data)); if ($part===false||$part==='') throw new \RuntimeException('Truncated recovery bundle/file'); $data.=$part; } return $data;
    }
    private static function write($out,string $data): void
    {
        $offset=0; while ($offset<strlen($data)) { $n=fwrite($out,substr($data,$offset)); if ($n===false||$n===0) throw new \RuntimeException('Recovery bundle write failed'); $offset+=$n; }
    }
    public static function cleanup(string $dir): void
    {
        if (!is_dir($dir)||is_link($dir)) return;
        foreach (new \FilesystemIterator($dir,\FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir()&&!$item->isLink()) self::cleanup($item->getPathname());
            elseif (!unlink($item->getPathname())) throw new \RuntimeException('Cannot clean recovery workspace');
        }
        if (!rmdir($dir)) throw new \RuntimeException('Cannot remove recovery workspace');
    }
}
