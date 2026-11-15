<?php

declare(strict_types=1);
namespace CajeerEngine\Privacy;

use CajeerEngine\Update\OperationFiles as F;

/** Fixed-name public assets, stored in resources to remain compatible with the 1.3.1 updater allowlist. */
final class ConsentAssets
{
    private const FILES=['/assets/ce-consent/consent.js'=>['consent.js','application/javascript; charset=utf-8'],'/assets/ce-consent/consent.css'=>['consent.css','text/css; charset=utf-8']];
    public static function matches(string $path): bool { return isset(self::FILES[$path]); }
    public static function version(string $root): string
    {
        $hash=hash_init('sha256');
        foreach(self::FILES as [$name]) hash_update($hash,F::hash(F::path($root,'resources/consent/'.$name)));
        return substr(hash_final($hash),0,12);
    }
    public static function response(string $root,string $path,string $method,?string $etag=null): array
    {
        $headers=['X-Content-Type-Options'=>'nosniff','Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8'];
        if(!self::matches($path))return [404,$headers,'Not found'];
        if(!in_array($method,['GET','HEAD'],true))return [405,$headers+['Allow'=>'GET, HEAD'],'Method not allowed'];
        try {
            [$name,$type]=self::FILES[$path];$file=F::path($root,'resources/consent/'.$name);
            if(!is_file($file))throw new \RuntimeException('Missing asset');
            $body=file_get_contents($file);if($body===false)throw new \RuntimeException('Unreadable asset');
            $tag='"'.hash('sha256',$body).'"';
            $headers=['X-Content-Type-Options'=>'nosniff','Cache-Control'=>'public, max-age=3600','Content-Type'=>$type,'ETag'=>$tag];
            if($etag===$tag||$etag==='*')return [304,$headers,''];
            return [200,$headers,$method==='HEAD'?'':$body];
        }catch(\Throwable){return [503,$headers,'Asset unavailable'];}
    }
}
