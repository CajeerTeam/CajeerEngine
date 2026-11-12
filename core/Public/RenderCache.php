<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

/** Content-addressed validation, one bounded entry per public path; preview never uses this cache. */
final readonly class RenderCache
{
    public function __construct(private string $root) {}
    public function render(string $path,array $context,callable $render): string
    {
        $ttl=max(0,min(3600,(int)(getenv('PUBLIC_RENDER_CACHE_TTL')?:0)));
        if($ttl===0)return $render();
        $dir=$this->root.'/storage/cache/public';
        $fingerprints=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/templates',\FilesystemIterator::SKIP_DOTS)) as $file){
            if($file->isFile()&&!$file->isLink()&&$file->getExtension()==='cjr')$fingerprints[$file->getPathname()]=hash_file('sha256',$file->getPathname());
        }
        ksort($fingerprints);
        $key=hash('sha256',json_encode([$context,$fingerprints,hash_file('sha256',__FILE__),hash_file('sha256',__DIR__.'/PublicPageRenderer.php'),hash_file('sha256',__DIR__.'/BlockRenderer.php'),PHP_VERSION],JSON_THROW_ON_ERROR));
        $file=$dir.'/'.hash('sha256',$path).'.json';
        if(is_file($file)){
            $record=json_decode((string)@file_get_contents($file),true);
            if(is_array($record)&&($record['key']??null)===$key&&($record['expires']??0)>time()&&is_string($record['html']??null))return $record['html'];
        }
        $html=$render();
        if(strlen($html)<=2*1024*1024){
            try {\CajeerEngine\Update\OperationFiles::write($file,json_encode(['key'=>$key,'expires'=>time()+$ttl,'html'=>$html],JSON_THROW_ON_ERROR),0640);}catch(\Throwable){/* cache failure cannot take the site offline */}
        }
        return $html;
    }
}
