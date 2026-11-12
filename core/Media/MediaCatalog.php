<?php

declare(strict_types=1);
namespace CajeerEngine\Media;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles as F;
use PDO;

/** The legacy JSON index is deliberately never used as a database failure fallback. */
final class MediaCatalog
{
    private readonly DatabaseManager $db;
    private readonly string $mode;
    private readonly JsonFile $file;
    public function __construct(private readonly string $root, ?DatabaseManager $database = null)
    {
        $this->db = $database ?? new DatabaseManager(new ConfigRepository($root));
        $this->mode = getenv('MEDIA_STORAGE') ?: 'file';
        if (!in_array($this->mode, ['file','database'], true)) throw new \InvalidArgumentException('MEDIA_STORAGE must be file or database');
        $this->file = new JsonFile(F::path($root, 'storage/app/media.json'));
    }
    public function mode(): string { return $this->mode; }
    private function pdo(): PDO { return $this->db->connection(); }
    private function ready(): void
    {
        $marker = is_file(F::path($this->root,'storage/app/media-migrated.json'));
        if ($this->mode === 'file' && $marker) throw new \RuntimeException('Media was migrated; set MEDIA_STORAGE=database');
        if ($this->mode === 'database' && !$marker && ($this->file->readObject()['items'] ?? []) !== []) throw new \RuntimeException('Legacy media exists; run media:migrate before using database mode');
    }
    public function legacy(): array { return $this->file->readObject(['items'=>[]])['items']; }
    public function migrate(callable $validate): array
    {
        $legacy = $this->legacy();
        if (is_file(F::path($this->root,'storage/app/media-migrated.json'))) return ['ok'=>true,'migrated'=>0,'already_migrated'=>true];
        $count = $this->db->transaction(function() use($legacy,$validate) {
            foreach ($legacy as $row) {
                $row = $validate($row);
                $existing = $this->findDb($row['id']);
                if ($existing !== null && $existing != $row) throw new \DomainException('Conflicting media ID during migration: '.$row['id']);
                if ($existing === null) $this->saveDb($row, false);
            }
            return count($legacy);
        });
        F::json(F::path($this->root,'storage/app/media-migrated.json'),['migrated'=>$count,'at'=>gmdate('c')],0660);
        return ['ok'=>true,'migrated'=>$count,'next'=>'Set MEDIA_STORAGE=database'];
    }
    private static function lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s); }
    private static function search(array $row): string { return self::lower(implode(' ',[$row['filename'],$row['title']??'',$row['alt']??'',$row['caption']??'',implode(' ',$row['tags']??[]),implode(' ',$row['collections']??[])])); }
    private static function unpack(string $json): array { return json_decode($json,true,512,JSON_THROW_ON_ERROR); }
    public function page(array $filter=[], ?int $limit=50, int $offset=0): array
    {
        $this->ready();
        $q=self::lower(trim((string)($filter['q']??''))); $folder=$filter['folder']??null; $mime=(string)($filter['mime']??''); $checksum=(string)($filter['checksum']??'');$visibility=(string)($filter['visibility']??'');
        if($this->mode==='database'){
            $where=[];$params=[];
            if($q!==''){$where[]="search_text LIKE :q ESCAPE '!'";$params['q']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';}
            if($folder!==null){$where[]='folder = :folder';$params['folder']=$folder;}
            if($mime!==''){$where[]='mime_type = :mime';$params['mime']=$mime;}
            if($checksum!==''){$where[]='checksum = :checksum';$params['checksum']=$checksum;}
            if($visibility!==''){$where[]='visibility = :visibility';$params['visibility']=$visibility;}
            $where=$where?' WHERE '.implode(' AND ',$where):'';
            $pdo=$this->pdo();$s=$pdo->prepare('SELECT COUNT(*) FROM ce_media'.$where);$s->execute($params);$total=(int)$s->fetchColumn();
            $s=$pdo->prepare('SELECT metadata FROM ce_media'.$where.' ORDER BY created_at DESC,id ASC'.($limit!==null?' LIMIT :lim OFFSET :off':''));
            foreach($params as $k=>$v)$s->bindValue($k,$v);
            if($limit!==null){$s->bindValue('lim',$limit,PDO::PARAM_INT);$s->bindValue('off',$offset,PDO::PARAM_INT);}
            $s->execute();$rows=[];while(($v=$s->fetchColumn())!==false)$rows[]=self::unpack($v);
        }else{
            $rows=array_values(array_filter($this->legacy(),static fn($r)=>($q===''||str_contains(self::search($r),$q))&&($folder===null||($r['folder']??'')===$folder)&&($mime===''||$r['mime_type']===$mime)&&($checksum===''||$r['checksum']===$checksum)&&($visibility===''||($r['visibility']??'public')===$visibility)));
            usort($rows,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at'])?:strcmp($a['id'],$b['id']));$total=count($rows);if($limit!==null)$rows=array_slice($rows,$offset,$limit);
        }
        return ['data'=>$rows,'meta'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset]];
    }
    public function folders(): array
    {
        $this->ready();
        if($this->mode==='database')return $this->pdo()->query('SELECT DISTINCT folder FROM ce_media ORDER BY folder')->fetchAll(PDO::FETCH_COLUMN);
        $folders=array_values(array_unique(array_map(static fn($r)=>$r['folder']??'',$this->legacy())));sort($folders);return $folders;
    }
    private function findDb(string $id): ?array
    {
        $s=$this->pdo()->prepare('SELECT metadata FROM ce_media WHERE id = :id');$s->execute(['id'=>$id]);$v=$s->fetchColumn();return $v===false?null:self::unpack($v);
    }
    public function find(string $id): ?array
    {
        $this->ready();
        if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id))return null;
        if($this->mode==='database')return $this->findDb($id);
        foreach($this->legacy() as $r)if($r['id']===$id)return $r;return null;
    }
    private function saveDb(array $row,bool $update): void
    {
        $p=['id'=>$row['id'],'path'=>$row['path'],'filename'=>$row['filename'],'mime'=>$row['mime_type'],'size'=>$row['size'],'metadata'=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'checksum'=>$row['checksum'],'folder'=>$row['folder']??'','search'=>self::search($row),'visibility'=>$row['visibility']??'public'];
        if($update)$sql='UPDATE ce_media SET path=:path,filename=:filename,mime_type=:mime,size_bytes=:size,metadata=:metadata,checksum=:checksum,folder=:folder,search_text=:search,visibility=:visibility WHERE id=:id';
        else {$sql="INSERT INTO ce_media (id,disk,path,filename,mime_type,size_bytes,metadata,checksum,folder,search_text,visibility,created_at) VALUES (:id,'local',:path,:filename,:mime,:size,:metadata,:checksum,:folder,:search,:visibility,:created)";$p['created']=gmdate('Y-m-d H:i:s',strtotime($row['created_at'])?:time());}
        $this->pdo()->prepare($sql)->execute($p);
    }
    public function save(array $row,bool $update=false): void
    {
        $this->ready();
        if($this->mode==='database'){$this->saveDb($row,$update);return;}
        $rows=$this->legacy();$found=false;foreach($rows as &$r)if($r['id']===$row['id']){if(!$update)throw new \DomainException('Duplicate media ID');$r=$row;$found=true;break;}unset($r);
        if(!$found){if($update)throw new \DomainException('Media no longer exists');$rows[]=$row;}
        $this->file->writeObject(['items'=>$rows,'updated_at'=>gmdate('c')]);
    }
    public function delete(string $id): void
    {
        $this->ready();
        if($this->mode==='database'){$this->pdo()->prepare('DELETE FROM ce_media WHERE id=:id')->execute(['id'=>$id]);return;}
        $this->file->writeObject(['items'=>array_values(array_filter($this->legacy(),fn($r)=>$r['id']!==$id)),'updated_at'=>gmdate('c')]);
    }
}
