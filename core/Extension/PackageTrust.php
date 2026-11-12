<?php
declare(strict_types=1);
namespace CajeerEngine\Extension;

use CajeerEngine\Update\OperationFiles as F;

/** Sign all executable and data files, not just the manifest. Trust keys are provisioned by the operator. */
final class PackageTrust
{
    public const FILE = 'extension-signature.json';
    public static function inventory(string $dir): array
    {
        if (!is_dir($dir)||is_link($dir)) throw new \RuntimeException('Invalid package directory');
        $out=[]; $bytes=0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $p=substr($f->getPathname(),strlen($dir)+1);
            if ($f->isLink() || !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D',$p)) throw new \RuntimeException('Unsafe package path: '.$p);
            if ($f->isDir()) continue;
            if (!$f->isFile()) throw new \RuntimeException('Not a regular package file');
            if ($p===self::FILE) continue;
            if (str_starts_with($p,'assets/') && !preg_match('/\.(?:css|js|mjs|png|jpe?g|webp|gif|ico|woff2?|ttf|json)$/iD',$p)) throw new \RuntimeException('Unsafe public asset: '.$p);
            $bytes+=$f->getSize(); if ($bytes>128*1024*1024 || count($out)>=4096) throw new \RuntimeException('Package limits exceeded');
            $out[$p]=F::hash($f->getPathname());
        }
        ksort($out,SORT_STRING); return $out;
    }
    private static function message(array $files): string { return "CajeerEngine extension inventory v1\n".json_encode($files,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    public static function sign(string $dir,string $keyFile): array
    {
        if (is_link($keyFile)||!is_file($keyFile)||(fileperms($keyFile)&0077)!==0) throw new \RuntimeException('Private key must be 0600');
        $key=base64_decode(trim((string)file_get_contents($keyFile)),true);
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new \RuntimeException('Invalid signing key');
        try { $files=self::inventory($dir); $manifest=(new ManifestLoader())->load($dir.'/'.ManifestLoader::FILENAME); $data=['format'=>'cajeer.extension.signature.v1','name'=>$manifest->name,'files'=>$files,'signature'=>base64_encode(sodium_crypto_sign_detached(self::message($files),$key))]; F::json($dir.'/'.self::FILE,$data,0644); return ['ok'=>true,'name'=>$manifest->name,'files'=>count($files)]; }
        finally { sodium_memzero($key); }
    }
    public static function verify(string $dir,ExtensionManifest $manifest,string $trustDir,bool $allowUnsigned=false): array
    {
        $files=self::inventory($dir); $path=$dir.'/'.self::FILE;
        if (!is_file($path)) { if (!$allowUnsigned) throw new \RuntimeException('Unsigned extension: '.$manifest->name); return ['ok'=>true,'status'=>'unsigned_dev','actual'=>hash('sha256',self::message($files)),'warnings'=>['Unsigned development extension']]; }
        $vendor=explode('/',$manifest->name)[0];
        if ($trustDir===''||!str_starts_with($trustDir,'/')||!preg_match('/^[a-z0-9][a-z0-9_.-]*$/D',$vendor)) throw new \RuntimeException('Trusted publisher directory required');
        $keyPath=F::path($trustDir,$vendor.'.pub');
        if (!is_file($keyPath)||filesize($keyPath)>4096) throw new \RuntimeException('Untrusted publisher: '.$vendor);
        $public=base64_decode(trim((string)file_get_contents($keyPath)),true);
        if (filesize($path)>1024*1024) throw new \RuntimeException('Signature inventory too large');
        $data=F::read($path); $sig=base64_decode((string)($data['signature']??''),true);
        if (($data['format']??null)!=='cajeer.extension.signature.v1'||($data['name']??null)!==$manifest->name||($data['files']??null)!==$files||!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($sig,self::message($files),$public)) throw new \RuntimeException('Extension signature/inventory mismatch: '.$manifest->name);
        return ['ok'=>true,'status'=>'verified','actual'=>hash('sha256',self::message($files)),'warnings'=>[]];
    }
}
