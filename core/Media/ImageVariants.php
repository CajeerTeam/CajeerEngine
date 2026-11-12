<?php

declare(strict_types=1);
namespace CajeerEngine\Media;

use CajeerEngine\Update\OperationFiles as F;

/** GD is optional for non-image sites; an explicitly configured ImageMagick binary is supported. */
final readonly class ImageVariants
{
    public const SIZES=['thumbnail'=>240,'small'=>480,'medium'=>960,'large'=>1600,'webp'=>960];
    public function __construct(private string $root) {}
    public function available(): bool { return function_exists('imagecreatetruecolor') || $this->binary()!==null; }
    private function binary(): ?string
    {
        $p=getenv('MEDIA_CONVERT_BINARY');return is_string($p)&&str_starts_with($p,'/')&&is_file($p)&&is_executable($p)?$p:null;
    }
    public function create(string $source,array $item): array
    {
        if(!in_array($item['mime_type'],['image/jpeg','image/png','image/webp','image/gif'],true)||!$this->available())return [];
        $w=(int)$item['width'];$h=(int)$item['height'];$variants=[];
        // Limit decoded memory independently of compressed upload size.
        if($w*$h>16000000)throw new \InvalidArgumentException('Image exceeds variant pixel limit (16 MP)');
        $defaultExt=$item['mime_type']==='image/jpeg'?'jpg':'png';
        $base='variants/'.$item['id'].'/'.$item['checksum'];
        $prefix=($item['visibility']??'public')==='private'?'storage/app/media-private/':'public/uploads/';
        $dir=F::path($this->root,$prefix.$base);F::dir($dir,$prefix==='public/uploads/'?0755:0770);
        foreach(self::SIZES as $name=>$max){
            $ext=$name==='webp'?'webp':$defaultExt;if($ext==='webp'&&function_exists('imagecreatetruecolor')&&!function_exists('imagewebp'))continue;
            $ratio=min(1,$max/max($w,$h));$tw=max(1,(int)round($w*$ratio));$th=max(1,(int)round($h*$ratio));
            $relative=$base.'/'.$name.'.'.$ext;$dest=F::path($this->root,$prefix.$relative);
            $tmp=tempnam(F::path($this->root,'storage/app/media-work'),'variant-');if($tmp===false)throw new \RuntimeException('Cannot allocate variant');
            try{
                if(function_exists('imagecreatetruecolor'))$this->gd($source,$tmp,$item['mime_type'],$ext,$tw,$th);
                else $this->convert($source,$tmp,$item['mime_type'],$ext,$tw,$th);
                $dim=@getimagesize($tmp);if(!$dim||$dim[0]!==$tw||$dim[1]!==$th||$dim['mime']!==match($ext){'jpg'=>'image/jpeg','webp'=>'image/webp',default=>'image/png'})throw new \RuntimeException('Invalid generated image');
                F::copy($tmp,$dest,$prefix==='public/uploads/'?0644:0640);$variants[$name]=['path'=>$relative,'width'=>$tw,'height'=>$th,'size'=>filesize($dest),'checksum'=>F::hash($dest),'mime_type'=>$dim['mime']];
            }finally{unlink($tmp);}
        }
        return $variants;
    }
    private function gd(string $source,string $dest,string $mime,string $ext,int $w,int $h): void
    {
        if(($limit=ini_get('memory_limit'))!==false&&$limit!=='-1'){ $bytes=(int)$limit; $suffix=strtolower(substr($limit,-1));$bytes*=match($suffix){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};$d=getimagesize($source);if($bytes>0&&memory_get_usage(true)+($d[0]*$d[1]+$w*$h)*6+16777216>$bytes)throw new \InvalidArgumentException('Image exceeds PHP memory budget'); }
        $loader=match($mime){'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/gif'=>'imagecreatefromgif','image/webp'=>'imagecreatefromwebp'};
        if(!function_exists($loader))throw new \RuntimeException('GD codec unavailable');
        $image=@$loader($source);if(!$image)throw new \InvalidArgumentException('Image cannot be decoded');
        $out=imagecreatetruecolor($w,$h);if(!$out)throw new \RuntimeException('Cannot allocate image');
        try{imagealphablending($out,false);imagesavealpha($out,true);imagefill($out,0,0,imagecolorallocatealpha($out,0,0,0,127));
            if(!imagecopyresampled($out,$image,0,0,0,0,$w,$h,imagesx($image),imagesy($image)))throw new \RuntimeException('Resize failed');
            if(!(match($ext){'jpg'=>imagejpeg($out,$dest,85),'webp'=>imagewebp($out,$dest,85),default=>imagepng($out,$dest,6)}))throw new \RuntimeException('Encode failed');
        }finally{imagedestroy($image);imagedestroy($out);}
    }
    private function convert(string $source,string $dest,string $mime,string $ext,int $w,int $h): void
    {
        $format=match($mime){'image/jpeg'=>'JPEG','image/png'=>'PNG','image/gif'=>'GIF','image/webp'=>'WEBP'};
        // Only a fixed raster decoder is selected; no shell, arbitrary delegates, URLs or user arguments.
        $command=[$this->binary(),'-limit','memory','192MiB','-limit','map','256MiB','-limit','disk','256MiB','-limit','time','20','-limit','thread','1',$format.':'.$source.'[0]','-strip','-resize',$w.'x'.$h.'!',match($ext){'jpg'=>'JPEG','webp'=>'WEBP',default=>'PNG'}.':'.$dest];
        $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($p))throw new \RuntimeException('Cannot start image processor');
        $deadline=microtime(true)+25;do{$s=proc_get_status($p);if(!$s['running'])break;if(microtime(true)>$deadline){proc_terminate($p,9);proc_close($p);throw new \RuntimeException('Image processor timed out');}usleep(20000);}while(true);
        $exit=proc_close($p);if($s['exitcode']!==0&&$exit!==0)throw new \InvalidArgumentException('Image processor rejected file');
    }
}
