<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles;
use PDO;

/** DB lookups use a unique hash index; file storage is a small-site/demo implementation. */
final class RouteIndex
{
    private static array $writing=[];
    private readonly DatabaseManager $db;
    private readonly ContentEntryRepository $entries;
    private readonly ContentTypeRepository $types;
    private readonly ThemeConfigRepository $theme;
    private readonly string $key;
    private readonly string $file;
    public function __construct(private readonly string $root,?DatabaseManager $db=null)
    {
        $this->db=$db??new DatabaseManager(new ConfigRepository($root));
        $this->entries=new ContentEntryRepository($root,$this->db);$this->types=new ContentTypeRepository($root,$this->db);$this->theme=new ThemeConfigRepository($root,$this->db);
        $this->key=realpath($root)?:$root;$this->file=$root.'/storage/app/cms/public-routes.json';
    }
    public function lookup(string $path): ?array
    {
        $path=PublicPath::normalize($path);
        if(PublicPath::reserved($path))return null;
        if($this->database()){
            $this->ensureDatabase();$s=$this->db->connection()->prepare('SELECT payload FROM ce_public_routes WHERE route_key = :key');$s->execute(['key'=>hash('sha256',$path)]);$r=$s->fetchColumn();
            $row=is_string($r)?json_decode($r,true,flags:JSON_THROW_ON_ERROR):null;
            return ($row['path']??null)===$path?$row:null;
        }
        return $this->fileRoutes()[$path]??null;
    }
    public function publishedEntry(string $path): ?array
    {
        $path=PublicPath::normalize($path);if(PublicPath::reserved($path))return null;
        if($this->database()){$this->ensureDatabase();return $this->entries->findPublishedRoute($path);}
        $this->fileRoutes();$h=$this->lock(LOCK_SH);
        try{$state=$this->readFile();if(($state['fingerprint']??null)!==$this->fingerprint())return null;
            $r=$state['routes'][$path]??null;if(($r['kind']??null)!=='page')return null;
            $entry=$this->entries->findById($r['type'],$r['id']);return $entry!==null&&$entry['status']==='published'?['route'=>$r,'entry'=>$entry]:null;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }

    public function forEntry(string $id): ?array
    {
        if($this->database()){
            $this->ensureDatabase();$s=$this->db->connection()->prepare("SELECT payload FROM ce_public_routes WHERE entry_id = :id AND kind = 'page'");$s->execute(['id'=>$id]);$r=$s->fetchColumn();return is_string($r)?json_decode($r,true,flags:JSON_THROW_ON_ERROR):null;
        }
        foreach($this->fileRoutes() as $row)if($row['id']===$id&&$row['kind']==='page')return $row;return null;
    }
    /** Batched indexed lookup for administrative content lists. */
    public function pathsForEntries(array $ids): array
    {
        $result=[];if(!$ids)return $result;
        if($this->database()){
            $this->ensureDatabase();foreach(array_chunk(array_values(array_unique($ids)),500) as $chunk){
                $s=$this->db->connection()->prepare("SELECT entry_id,payload FROM ce_public_routes WHERE kind='page' AND entry_id IN (".implode(',',array_fill(0,count($chunk),'?')).")");$s->execute($chunk);
                while($row=$s->fetch(PDO::FETCH_ASSOC))$result[$row['entry_id']]=json_decode($row['payload'],true,flags:JSON_THROW_ON_ERROR)['path'];
            }
        }else{foreach($this->fileRoutes() as $r)if($r['kind']==='page')$result[$r['id']]=$r['path'];}
        return $result;
    }

    public function all(): array
    {
        if($this->database()){$this->ensureDatabase();return $this->readDatabase();}
        return $this->fileRoutes();
    }
    public function page(int $limit,int $offset): array
    {
        $limit=max(1,min(1000,$limit));$offset=max(0,$offset);
        if($this->database()){
            $this->ensureDatabase();$pdo=$this->db->connection();$total=(int)$pdo->query('SELECT COUNT(*) FROM ce_public_routes')->fetchColumn();
            $stmt=$pdo->prepare('SELECT payload FROM ce_public_routes ORDER BY path LIMIT :limit OFFSET :offset');$stmt->bindValue('limit',$limit,PDO::PARAM_INT);$stmt->bindValue('offset',$offset,PDO::PARAM_INT);$stmt->execute();$items=[];
            while(($v=$stmt->fetchColumn())!==false)$items[]=json_decode($v,true,flags:JSON_THROW_ON_ERROR);
        }else{$routes=$this->fileRoutes();ksort($routes,SORT_STRING);$total=count($routes);$items=array_slice(array_values($routes),$offset,$limit);}
        return ['data'=>$items,'meta'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset]];
    }
    public function check(): array
    {
        $stored=$this->database()?$this->readDatabase():($this->readFile()['routes']??[]);
        $expected=$this->graph($stored);ksort($stored);ksort($expected);
        return ['ok'=>$stored===$expected,'pages'=>count(array_filter($expected,fn($r)=>$r['kind']==='page')),'aliases'=>count(array_filter($expected,fn($r)=>$r['kind']==='alias')),'storage'=>$this->database()?'database':'file','message'=>$stored===$expected?'Route index is consistent':'Run routing:rebuild'];
    }
    public function rebuild(): array
    {
        return $this->mutate(fn()=>null,[],false,true);
    }
    /** Every repository mutation uses this gate. Bulk import has its own authenticated rollback. */
    public function mutate(callable $callback,array $files=[],bool $externalRollback=false,bool $report=false): mixed
    {
        if(isset(self::$writing[$this->key]))return $callback();
        $lock=$this->lock(LOCK_EX);self::$writing[$this->key]=true;$snap=[];
        try {
            foreach($files as $relative){$p=OperationFiles::path($this->root,$relative);$snap[$p]=is_file($p)?['bytes'=>file_get_contents($p),'mode'=>fileperms($p)&0777]:null;}
            $work=function()use($callback,$report){
                if($this->database()){
                    $pdo=$this->db->connection();$pdo->exec('UPDATE ce_public_route_state SET generation = generation + 1 WHERE id = 1');
                    $stored=$previous=$this->readDatabase();
                    // Preserve old routes even when the index is being initialized by the first edit.
                    if($previous===[]) { try { $previous=$this->graph([]); } catch (\DomainException|\InvalidArgumentException) { /* Allow an editor to repair legacy collisions. */ } }
                    $result=$callback();$routes=$this->graph($previous);$this->writeDatabase($routes,$stored);
                }else{
                    $previous=$this->readFile()['routes']??[];
                    if($previous===[]) { try { $previous=$this->graph([]); } catch (\DomainException|\InvalidArgumentException) { /* Allow an editor to repair legacy collisions. */ } }
                    $result=$callback();$routes=$this->graph($previous);$this->writeFile($routes);
                }
                return $report?['ok'=>true,'pages'=>count(array_filter($routes,fn($r)=>$r['kind']==='page')),'aliases'=>count(array_filter($routes,fn($r)=>$r['kind']==='alias')),'storage'=>$this->database()?'database':'file']:$result;
            };
            return ($this->database()||$this->theme->storage()==='database')?$this->db->transaction($work):$work();
        }catch(\Throwable $e){
            if(!$externalRollback)foreach($snap as $p=>$old){if($old===null){if(is_file($p))OperationFiles::remove($p);}elseif(file_get_contents($p)!==$old['bytes'])OperationFiles::write($p,$old['bytes'],$old['mode']);}
            throw $e;
        }finally{unset(self::$writing[$this->key]);flock($lock,LOCK_UN);fclose($lock);}
    }
    public function previewPath(string $id): ?string
    {
        return $this->graph([], $id)['path']??null;
    }
    private function graph(array $previous,?string $previewId=null): array
    {
        $rows=[];foreach($this->types->all() as $type)foreach($this->entries->list($type['handle']) as $row){unset($row['revisions']);$rows[]=$row;}
        return RouteGraph::build($rows,$this->theme->get(),$previous,$previewId);
    }
    private function database(): bool {return $this->entries->storage()==='database';}
    private function ensureDatabase(): void
    {
        $v=$this->db->connection()->query('SELECT generation FROM ce_public_route_state WHERE id = 1')->fetchColumn();
        if($v===false)throw new \RuntimeException('Public route index is not initialized; run migrate and routing:rebuild');
        if((int)$v===0)$this->rebuild();
    }
    private function readDatabase(): array
    {
        $s=$this->db->connection()->query('SELECT payload FROM ce_public_routes');$routes=[];
        while(($v=$s->fetchColumn())!==false){$r=json_decode($v,true,flags:JSON_THROW_ON_ERROR);$routes[$r['path']]=$r;}return $routes;
    }
    private function writeDatabase(array $routes,array $previous): void
    {
        $pdo=$this->db->connection();$delete=$pdo->prepare('DELETE FROM ce_public_routes WHERE route_key = :key');
        foreach($previous as $path=>$old)if(!isset($routes[$path])||$routes[$path]!==$old)$delete->execute(['key'=>hash('sha256',$path)]);
        $s=$pdo->prepare('INSERT INTO ce_public_routes (route_key, path, entry_id, kind, payload) VALUES (:key, :path, :id, :kind, :payload)');
        foreach($routes as $path=>$r)if(!isset($previous[$path])||$previous[$path]!==$r)$s->execute(['key'=>hash('sha256',$r['path']),'path'=>$r['path'],'id'=>$r['id'],'kind'=>$r['kind'],'payload'=>json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    private function fileRoutes(): array
    {
        // A crashed writer cannot leave a stale publication visible: source inode/mtime fingerprint changes on atomic writes.
        if(isset(self::$writing[$this->key]))return $this->graph($this->readFile()['routes']??[]);
        $lock=$this->lock(LOCK_SH);
        try{$state=$this->readFile();if(($state['fingerprint']??null)===$this->fingerprint())return $state['routes'];}
        finally{flock($lock,LOCK_UN);fclose($lock);}
        $this->rebuild();$lock=$this->lock(LOCK_SH);
        try{return $this->readFile()['routes']??[];}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function readFile(): array {return (new JsonFile($this->file))->readObject([]);}
    private function writeFile(array $routes): void
    {
        (new JsonFile($this->file))->writeObject(['fingerprint'=>$this->fingerprint(),'routes'=>$routes]);
    }
    private function fingerprint(): string
    {
        $files=array_merge([$this->root.'/storage/app/content-types.json'],glob($this->root.'/storage/app/content/*.json')?:[]);sort($files);$state=[];
        foreach($files as $p){clearstatcache(true,$p);$s=@stat($p);$state[$p]=$s?[$s['ino'],$s['mtime'],$s['size']]:null;}
        return hash('sha256',serialize([$state,$this->theme->get()]));
    }
    public function withWriteLock(callable $callback): mixed
    {
        if(isset(self::$writing[$this->key]))return $callback();
        $h=$this->lock(LOCK_EX);try{return $callback();}finally{flock($h,LOCK_UN);fclose($h);}
    }
    private function lock(int $mode): mixed
    {
        $path=OperationFiles::path($this->root,'storage/app/routing.lock');OperationFiles::dir(dirname($path),0775);
        $h=@fopen($path,'x+b');if($h!==false){chmod($path,0660);}else{$h=fopen($path,'r+b');}if($h===false)throw new \RuntimeException('Cannot open routing lock');
        if(!flock($h,$mode)){fclose($h);throw new \RuntimeException('Cannot acquire routing lock');}return $h;
    }
}
