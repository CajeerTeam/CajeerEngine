<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

/** Canonical URL paths are ASCII, with UTF-8 segments percent encoded exactly once. */
final class PublicPath
{
    public static function normalize(string $path): string
    {
        if (strlen($path)>2048 || preg_match('~[\\\\\x00-\x20\x7f?#]|%(?![0-9a-fA-F]{2})|%(?:2f|5c|25|00)~i', $path) || str_starts_with($path,'//')) throw new \InvalidArgumentException('Invalid public path');
        $parts=[];
        foreach(explode('/',trim($path,'/')) as $part){
            if($part==='') continue;
            $decoded=rawurldecode($part);
            if($decoded==='.'||$decoded==='..'||!preg_match('//u',$decoded)||preg_match('~[\\\\/\x00-\x20\x7f?#]~',$decoded)) throw new \InvalidArgumentException('Invalid public path segment');
            $parts[]=rawurlencode($decoded);
        }
        $result='/'.implode('/',$parts);
        if(strlen($result)>1024) throw new \InvalidArgumentException('Public path is too long');
        return $result;
    }
    public static function reserved(string $path): bool
    {
        $first=strtolower(rawurldecode(explode('/',ltrim($path,'/'))[0]));
        return str_starts_with($first,'.') || in_array($first,['api','admin','uploads','install','upgrade','preview','extensions','assets','health','metrics','robots.txt','sitemap.xml','index.php','install.php','upgrade.php'],true) || str_ends_with($first,'.php');
    }
    public static function content(string $path): string
    {
        $path=self::normalize($path);
        if(self::reserved($path)) throw new \InvalidArgumentException('Reserved public path: '.$path);
        return $path;
    }
    public static function base(string $url): string
    {
        $p=parse_url($url);
        if(!is_array($p)||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)||preg_match('~[\\\\\x00-\x20\x7f]~',$url)) throw new \InvalidArgumentException('APP_URL must be an HTTP(S) origin');
        return rtrim($url,'/');
    }
    public static function target(string $url): string
    {
        if($url===''||strlen($url)>4096||preg_match('~[\\\\\x00-\x20\x7f]|%(?:0d|0a|00|5c)~i',$url)||str_starts_with($url,'//')) throw new \InvalidArgumentException('Invalid redirect target');
        $p=parse_url($url);
        if(!is_array($p)) throw new \InvalidArgumentException('Invalid redirect target');
        if(str_starts_with($url,'/')) { self::normalize($p['path']??'/'); return $url; }
        if(!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||filter_var($url,FILTER_VALIDATE_URL)===false) throw new \InvalidArgumentException('Only HTTP(S) redirect targets are supported');
        return $url;
    }
    public static function localTarget(string $url,string $base): ?string
    {
        self::target($url); $p=parse_url($url);
        if(str_starts_with($url,'/')) return self::normalize($p['path']??'/');
        $b=parse_url($base);$port=static fn($x)=>$x['port']??(strtolower($x['scheme'])==='https'?443:80);
        if(strtolower($p['host'])===strtolower($b['host'])&&$port($p)===$port($b)) return self::normalize($p['path']??'/');
        return null;
    }
}
