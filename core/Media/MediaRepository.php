<?php

declare(strict_types=1);
namespace CajeerEngine\Media;

use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Support\Uuid;
use CajeerEngine\Update\OperationFiles as F;

/** Public originals retain their UUID and URL. All writes are serialized on a single-node installation. */
final class MediaRepository
{
    private readonly MediaCatalog $catalog;
    private readonly ImageVariants $images;
    public function __construct(private readonly string $rootPath, private readonly StorageManager $storage, ?\CajeerEngine\Database\DatabaseManager $database = null)
    { $this->catalog=new MediaCatalog($rootPath,$database);$this->images=new ImageVariants($rootPath); }

    public function all(string $query=''): array {return $this->catalog->page(['q'=>$query],null)['data'];}
    public function publicItems(): array {return $this->catalog->page(['visibility'=>'public'],null)['data'];}
    public function page(array $filter=[],int $limit=50,int $offset=0): array {return $this->catalog->page($filter,max(1,min(200,$limit)),max(0,$offset));}
    public function find(string $id): ?array {return $this->catalog->find($id);}
    public function folders(): array {return $this->catalog->folders();}
    public function count(): int {return $this->page([],1)['meta']['total'];}
    private function work(): string {$p=F::path($this->rootPath,'storage/app/media-work');F::dir($p,0770);return $p;}
    private function path(string $relative,string $visibility='public'): string {return F::path($this->rootPath,($visibility==='private'?'storage/app/media-private/':'public/uploads/').$relative);}
    public function sourcePath(array $row): string {return $this->path($row['path'],$row['visibility']??'public');}
    private function url(array $row,?string $variant=null): string
    {
        if(($row['visibility']??'public')==='public')return $this->storage->publicUrl($variant===null?$row['path']:$row['variants'][$variant]['path']);
        return '/api/v1/media/'.$row['id'].'/download'.($variant===null?'':'?variant='.$variant.'&version='.$row['checksum']);
    }
    private function mode(array $row): int {return ($row['visibility']??'public')==='private'?0640:0644;}
    public function visibility(array $payload): string { $v=$payload['visibility']??'public';if(!in_array($v,['public','private'],true))throw new \InvalidArgumentException('Invalid visibility');return $v; }

    private function locked(callable $fn,bool $recover=true): mixed
    {
        $path=F::path($this->rootPath,'storage/app/media.lock');F::dir(dirname($path),0775);
        $h=@fopen($path,'x+b');if($h!==false)chmod($path,0660);else $h=fopen($path,'r+b');
        if(!$h||!flock($h,LOCK_EX)){if(is_resource($h))fclose($h);throw new \RuntimeException('Cannot acquire media lock');}
        try{if($recover)$this->recoverLocked();return $fn();}finally{flock($h,LOCK_UN);fclose($h);}
    }
    public function createFromBase64(array $payload): array
    {
        $s=$payload['content_base64']??'';if(!is_string($s)||$s==='')throw new \InvalidArgumentException('content_base64 is required');
        if(str_contains($s,','))$s=substr($s,strpos($s,',')+1);
        if(strlen($s)>4*ceil($this->maxBytes()/3)+4)throw new \InvalidArgumentException('File too large');
        $bytes=base64_decode($s,true);if($bytes===false)throw new \InvalidArgumentException('Invalid base64');
        $p=tempnam($this->work(),'upload-');if($p===false)throw new \RuntimeException('Cannot stage upload');
        try{F::write($p,$bytes);return $this->createFromPath($p,(string)($payload['filename']??'upload.bin'),$payload);}finally{if(is_file($p))unlink($p);}
    }
    public function createFromUploadedFile(object $file,array $payload=[]): array
    {return $this->createFromPath($this->uploadPath($file),(string)$file->getClientOriginalName(),$payload);}
    private function uploadPath(object $file): string
    {
        if(!method_exists($file,'getClientOriginalName')||!method_exists($file,'getPathname')||!method_exists($file,'isValid')||!$file->isValid())throw new \InvalidArgumentException('Invalid upload');
        return (string)$file->getPathname();
    }
    public function createFromPath(string $source,string $filename,array $payload=[]): array
    {
        return $this->locked(function()use($source,$filename,$payload){
            $meta=$this->inspectImportFile($source,$filename);$edit=$this->metadata($payload);$visibility=$this->visibility($payload);
            if(filter_var($payload['deduplicate']??false,FILTER_VALIDATE_BOOLEAN)){
                foreach($this->catalog->page(['checksum'=>$meta['checksum'],'visibility'=>$visibility],null)['data'] as $r){
                    $p=$this->sourcePath($r);if(is_file($p)&&hash_equals($r['checksum'],F::hash($p)))return $r+['deduplicated'=>true];
                }
            }
            $path=$this->storage->makeRelativeUploadPath($meta['filename']);$now=gmdate('c');
            $record=$meta+$edit+['id'=>Uuid::v4(),'path'=>$path,'visibility'=>$visibility,'storage'=>$visibility.'-local','created_at'=>$now,'updated_at'=>$now];$record['url']=$this->url($record);return $this->persistNew($source,$record);
        });
    }
    public function inspectImportFile(string $path,string $filename): array
    {
        if(!is_file($path)||is_link($path))throw new \InvalidArgumentException('Missing media file');
        $filename=$this->sanitizeFilename($filename);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$size=filesize($path);
        if(!is_string($mime)||$size===false)throw new \InvalidArgumentException('Cannot inspect media');
        $this->assertAllowed($filename,$mime,$size);
        $info=['filename'=>$filename,'mime_type'=>$mime,'size'=>$size,'checksum'=>F::hash($path)];
        if(str_starts_with($mime,'image/')){
            $dim=@getimagesize($path);if(!$dim||($dim['mime']??'')!==$mime||$dim[0]<=0||$dim[1]<=0||$dim[0]*$dim[1]>16000000)throw new \InvalidArgumentException('Invalid image or pixel limit exceeded');
            $info+=['width'=>$dim[0],'height'=>$dim[1]];
        }
        return $info;
    }
    public function importFile(string $source,array $item): array
    {
        return $this->locked(function()use($source,$item){
            $meta=$this->inspectImportFile($source,(string)$item['filename']);
            if($meta['checksum']!==$item['checksum']||!$this->validId($item['id'])||$this->find($item['id'])!==null)throw new \RuntimeException('Media import identity changed');
            $path=(string)$item['path'];if(!preg_match('~^(?:import/|[0-9]{4}/[0-9]{2}/)[a-f0-9-]+\.[a-z0-9]+$~D',$path)||pathinfo($path,PATHINFO_EXTENSION)!==pathinfo($meta['filename'],PATHINFO_EXTENSION))throw new \InvalidArgumentException('Invalid import media path');
            $now=gmdate('c');$visibility=$this->visibility($item);$record=$meta+$this->metadata($item)+['id'=>$item['id'],'path'=>$path,'visibility'=>$visibility,'storage'=>$visibility.'-local','created_at'=>$now,'updated_at'=>$now];$record['url']=$this->url($record);return $this->persistNew($source,$record);
        });
    }
    private function persistNew(string $source,array $record): array
    {
        $dest=$this->sourcePath($record);if(file_exists($dest))throw new \DomainException('Media destination exists');
        $stage=tempnam($this->work(),'original-');if($stage===false)throw new \RuntimeException('Cannot stage original');
        try{
            F::copy($source,$stage);if(F::hash($stage)!==$record['checksum'])throw new \RuntimeException('Media changed during upload');
            $record['variants']=$this->variants($stage,$record);
            F::dir(dirname($dest),$this->mode($record)===0644?0755:0770);F::copy($stage,$dest,$this->mode($record));
            try{$this->catalog->save($record);}catch(\Throwable $e){F::remove($dest);throw $e;}
            return $record;
        }finally{if(is_file($stage))unlink($stage);}
    }
    private function variants(string $source,array $item): array
    {
        $this->work();if(str_starts_with($item['mime_type'],'image/')&&!$this->images->available()&&getenv('MEDIA_REQUIRE_IMAGE_PROCESSOR')==='true')throw new \RuntimeException('GD or MEDIA_CONVERT_BINARY required for image uploads');$v=$this->images->create($source,$item);$item['variants']=$v;foreach($v as $name=>&$row)$row['url']=$this->url($item,$name);unset($row);return $v;
    }
    public function update(string $id,array $payload): array
    {
        return $this->locked(function()use($id,$payload){$item=$this->required($id);$item=array_replace($item,$this->metadata($payload,$item));$item['updated_at']=gmdate('c');$this->catalog->save($item,true);return $item;});
    }
    public function replaceUploaded(string $id,object $file): array {return $this->replace($id,$this->uploadPath($file),(string)$file->getClientOriginalName());}
    public function replace(string $id,string $source,string $filename): array
    {
        return $this->locked(function()use($id,$source,$filename){
            $old=$this->required($id);$meta=$this->inspectImportFile($source,$filename);
            if($old['mime_type']!==$meta['mime_type']||pathinfo($old['path'],PATHINFO_EXTENSION)!==pathinfo($meta['filename'],PATHINFO_EXTENSION))throw new \InvalidArgumentException('Replacement must retain MIME and extension to preserve URL');
            if($meta['checksum']===$old['checksum'])return $old;
            $dest=$this->sourcePath($old);if(F::hash($dest)!==$old['checksum'])throw new \RuntimeException('Original is damaged; restore backup first');
            $stage=tempnam($this->work(),'replace-');if($stage===false)throw new \RuntimeException('Cannot stage replacement');
            try{
                F::copy($source,$stage);if(F::hash($stage)!==$meta['checksum'])throw new \RuntimeException('Replacement changed during staging');
                $new=array_replace($old,$meta,['updated_at'=>gmdate('c')]);unset($new['s3_replica']);$new['variants']=$this->variants($stage,$new);
                // Keep old variant URLs alive until the media is explicitly deleted.
                $retired=[];foreach(array_merge($old['retired_variants']??[],array_values($old['variants']??[])) as $v)$retired[$v['path']]=$v;foreach($new['variants'] as $v)unset($retired[$v['path']]);$new['retired_variants']=array_values($retired);
                $journal=$this->work().'/replacement.json';$backup=$this->work().'/replacement.original';
                F::copy($dest,$backup);F::json($journal,['id'=>$id,'path'=>$old['path'],'old'=>$old['checksum'],'new'=>$new['checksum']],0660);
                try{F::copy($stage,$dest,$this->mode($new));$this->catalog->save($new,true);}catch(\Throwable $e){$this->recoverLocked();throw $e;}
                $this->recoverLocked();return $new;
            }finally{if(is_file($stage))unlink($stage);}
        });
    }
    private function recoverLocked(): bool
    {
        $journal=$this->work().'/replacement.json';if(!is_file($journal))return false;
        $j=F::read($journal);$row=$this->required($j['id']);$dest=$this->sourcePath($row);$backup=$this->work().'/replacement.original';
        if($row['path']!==$j['path'])throw new \RuntimeException('Replacement recovery identity mismatch');
        if($row['checksum']===$j['old']){if(F::hash($backup)!==$j['old'])throw new \RuntimeException('Replacement recovery backup damaged');F::copy($backup,$dest,$this->mode($row));}
        elseif($row['checksum']!==$j['new']||F::hash($dest)!==$j['new'])throw new \RuntimeException('Replacement recovery requires operator');
        F::remove($journal);F::remove($backup);return true;
    }
    public function recover(): array {return $this->locked(fn()=>['ok'=>true,'recovered'=>$this->recoverLocked()],false);}
    public function usage(string $id): array {return (new MediaUsage($this->rootPath))->find($this->required($id));}
    public function delete(string $id): bool
    {
        // Use the same routing mutation lock as content/theme/navigation writes during the usage check.
        $p=F::path($this->rootPath,'storage/app/routing.lock');F::dir(dirname($p),0775);$h=@fopen($p,'x+b');if($h!==false)chmod($p,0660);else $h=fopen($p,'r+b');
        if(!$h||!flock($h,LOCK_EX)){if(is_resource($h))fclose($h);throw new \RuntimeException('Cannot lock content writes');}
        try{return $this->locked(function()use($id){
            $row=$this->find($id);if($row===null)return false;
            if($this->usage($id)!==[])throw new \DomainException('Media is used by content, revisions or CMS settings');
            $paths=[$row['path']];foreach(array_merge(array_values($row['variants']??[]),$row['retired_variants']??[]) as $v)$paths[]=$v['path'];
            foreach($paths as $path)$this->path($path,$row['visibility']??'public'); // validate all paths before removing metadata
            $this->catalog->delete($id);foreach(array_unique($paths) as $path)F::remove($this->path($path,$row['visibility']??'public'));return true;
        });}finally{flock($h,LOCK_UN);fclose($h);}
    }
    public function regenerate(string $id): array
    {
        return $this->locked(function()use($id){$row=$this->required($id);$path=$this->sourcePath($row);if(F::hash($path)!==$row['checksum'])throw new \RuntimeException('Original checksum mismatch');
            if(str_starts_with($row['mime_type'],'image/')&&!$this->images->available())throw new \RuntimeException('GD or MEDIA_CONVERT_BINARY required');
            $row['variants']=$this->variants($path,$row);$row['updated_at']=gmdate('c');$this->catalog->save($row,true);return $row;});
    }
    public function migrate(): array
    {
        return $this->locked(fn()=> $this->catalog->migrate(function($row){$meta=$this->inspectImportFile($this->sourcePath($row),$row['filename']);if($meta['checksum']!==$row['checksum']||!$this->validId($row['id']))throw new \RuntimeException('Invalid legacy media');return array_replace(['visibility'=>'public','storage'=>'public-local','variants'=>[]],$row,$meta,$this->metadata($row));}));
    }
    public function diagnostics(bool $verify=false): array
    {
        $items=$this->all();$bytes=0;$missing=[];$damaged=[];$known=[];
        foreach($items as $row){$bytes+=$row['size'];foreach(array_merge([$row],array_values($row['variants']??[]),$row['retired_variants']??[]) as $v){$visibility=$row['visibility']??'public';$p=$this->path($v['path'],$visibility);$known[$visibility.'/'.$v['path']]=true;if(!is_file($p))$missing[]=$v['path'];elseif($verify&&F::hash($p)!==$v['checksum'])$damaged[]=$v['path'];}}
        $orphans=[];if($verify)foreach(['public'=>'public/uploads','private'=>'storage/app/media-private'] as $visibility=>$prefix)foreach(F::files($this->rootPath,$prefix) as $p){$r=substr($p,strlen($prefix)+1);if(!isset($known[$visibility.'/'.$r])&&basename($r)!=='.gitkeep'&&basename($r)!=='.htaccess')$orphans[]=$visibility.'/'.$r;}
        $pending=is_file($this->rootPath.'/storage/app/media-work/replacement.json');
        return ['ok'=>!$missing&&!$damaged&&!$pending,'items'=>count($items),'bytes'=>$bytes,'missing_files'=>count($missing),'missing'=>$missing,'damaged'=>$damaged,'orphans'=>$orphans,'metadata_storage'=>$this->catalog->mode(),'variant_processor'=>$this->images->available(),'recovery_pending'=>$pending,'public_root'=>$this->storage->publicUploadsRoot(),'public_url'=>$this->storage->publicUploadsUrl()];
    }
    public function repair(): array
    {
        $this->recover();$repaired=[];$errors=[];
        foreach($this->all() as $row){try{if(str_starts_with($row['mime_type'],'image/')){$this->regenerate($row['id']);$repaired[]=$row['id'];}}catch(\Throwable $e){$errors[$row['id']]=$e->getMessage();}}
        $report=$this->diagnostics(true);return ['ok'=>$report['ok']&&!$errors,'repaired'=>$repaired,'errors'=>$errors,'diagnostics'=>$report];
    }
    /** A private file is never placed in the public document root. Signed access is bound to exact bytes. */
    public function download(string $id,?string $variant=null,?string $version=null,bool $verify=true): array
    {
        $row=$this->required($id);$file=$row;
        if($version==='')$version=null;
        if($version!==null&&!preg_match('/^[a-f0-9]{64}$/D',$version))throw new \InvalidArgumentException('Invalid variant version');
        if($variant!==null&&$variant!==''){
            if(!isset(ImageVariants::SIZES[$variant]))throw new \InvalidArgumentException('Unknown variant');
            if($version===null||$version===$row['checksum'])$file=$row['variants'][$variant]??throw new \InvalidArgumentException('Variant not found');
            else { $file=null;foreach($row['retired_variants']??[] as $candidate)if(str_contains($candidate['path'],'/'.$version.'/'.$variant.'.')){$file=$candidate;break;}if(!$file)throw new \InvalidArgumentException('Variant not found'); }
        }
        $path=$this->path($file['path'],$row['visibility']??'public');if($verify&&(!is_file($path)||F::hash($path)!==$file['checksum']))throw new \RuntimeException('Missing or damaged media');
        return $file+['local_path'=>$path,'filename'=>$variant?basename($file['path']):$row['filename'],'id'=>$id,'visibility'=>$row['visibility']??'public'];
    }
    private function signingKey(): string
    {
        $key=getenv('APP_KEY');if(!is_string($key)||strlen($key)<32)throw new \RuntimeException('APP_KEY is required for signed media URLs');return hash_hmac('sha256','cajeer-media-v1',$key,true);
    }
    public function signedUrl(string $id,int $ttl=300,?string $variant=null,?string $version=null,bool $s3=false): array
    {
        if($ttl<1||$ttl>900)throw new \InvalidArgumentException('Signed link TTL must be 1–900 seconds');
        $file=$this->download($id,$variant,$version);$expires=time()+$ttl;
        if($s3){$row=$this->required($id);if($variant!==null&&$variant!=='')throw new \InvalidArgumentException('S3 replica currently supports originals');$replica=$row['s3_replica']??null;if(($replica['checksum']??null)!==$file['checksum'])throw new \InvalidArgumentException('Run media:s3-sync first');$disk=$this->storage->disk('s3');if($disk->fileChecksum($replica['key'])!==$file['checksum'])throw new \RuntimeException('S3 replica damaged; resynchronize');return ['url'=>$disk->presignedUrl($replica['key'],'GET',$ttl,true),'expires_at'=>$expires,'backend'=>'s3'];}
        $query=['expires'=>$expires,'variant'=>$variant??'','version'=>$version??''];$query['signature']=hash_hmac('sha256',implode('|',[$id,$file['checksum'],$expires,$query['variant'],$query['version']]),$this->signingKey());
        return ['url'=>'/api/v1/media/'.$id.'/signed-download?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),'expires_at'=>$expires,'backend'=>'local'];
    }
    public function signedDownload(string $id,int $expires,string $signature,?string $variant=null,?string $version=null): array
    {
        if($expires<time()||$expires>time()+900||!preg_match('/^[a-f0-9]{64}$/D',$signature))throw new \DomainException('Invalid or expired media link');
        $file=$this->download($id,$variant,$version,false);$expected=hash_hmac('sha256',implode('|',[$id,$file['checksum'],$expires,$variant??'',$version??'']),$this->signingKey());
        if(!hash_equals($expected,$signature))throw new \DomainException('Invalid or expired media link');if(!is_file($file['local_path'])||F::hash($file['local_path'])!==$file['checksum'])throw new \DomainException('Invalid or expired media link');return $file;
    }
    public function syncReplica(string $id): array
    {
        return $this->locked(function()use($id){$row=$this->required($id);$file=$this->download($id);$key='media/'.$id.'/'.$row['checksum'].'/'.basename($row['path']);$disk=$this->storage->disk('s3');$disk->putFile($key,$file['local_path']);if($disk->fileChecksum($key)!==$row['checksum'])throw new \RuntimeException('S3 replica checksum mismatch');$row['s3_replica']=['key'=>$key,'checksum'=>$row['checksum'],'verified_at'=>gmdate('c')];$this->catalog->save($row,true);return $row;});
    }
    private function required(string $id): array {return $this->find($id)??throw new \InvalidArgumentException('Media не найден');}
    private function validId(mixed $id): bool {return is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id)===1;}
    public function metadata(array $payload,array $old=[]): array
    {
        $out=[];foreach(['title'=>255,'alt'=>1000,'caption'=>4000] as $key=>$max){$v=$payload[$key]??$old[$key]??'';if(!is_string($v)||strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new \InvalidArgumentException('Invalid media '.$key);$out[$key]=$v;}
        $folder=$payload['folder']??$old['folder']??'';if(!is_string($folder)||strlen($folder)>480||($folder!==''&&!preg_match('~^[\pL\pN _-]+(?:/[\pL\pN _-]+)*$~uD',$folder)))throw new \InvalidArgumentException('Invalid folder');$out['folder']=$folder;
        foreach(['tags','collections'] as $key){$v=$payload[$key]??$old[$key]??[];if(is_string($v))$v=$v===''?[]:array_map('trim',explode(',',$v));if(!is_array($v)||!array_is_list($v)||count($v)>40)throw new \InvalidArgumentException('Invalid '.$key);foreach($v as $s)if(!is_string($s)||$s===''||strlen($s)>100||preg_match('/[\x00-\x1f]/',$s))throw new \InvalidArgumentException('Invalid '.$key);$out[$key]=array_values(array_unique($v));}return $out;
    }
    private function maxBytes(): int {return max(1,min(100,(int)(getenv('MEDIA_MAX_UPLOAD_MB')?:25)))*1024*1024;}
    private function assertAllowed(string $filename,string $mime,int $size): void
    {
        $allowed=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'gif'=>['image/gif'],'webp'=>['image/webp'],'pdf'=>['application/pdf'],'txt'=>['text/plain'],'md'=>['text/plain','text/markdown'],'zip'=>['application/zip','application/x-zip-compressed']];
        if(!in_array($mime,$allowed[pathinfo($filename,PATHINFO_EXTENSION)]??[],true)||$size<=0||$size>$this->maxBytes())throw new \InvalidArgumentException('Invalid file size or MIME/extension mismatch');
    }
    private function sanitizeFilename(string $filename): string
    {
        $filename=basename(str_replace('\\','/',$filename));$name=trim((string)preg_replace('/[^a-zA-Z0-9_-]+/','-',pathinfo($filename,PATHINFO_FILENAME)),'-_.')?:'upload';$ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
        return substr($name,0,200).'.'.(preg_replace('/[^a-z0-9]+/','',$ext)?:'bin');
    }
}
