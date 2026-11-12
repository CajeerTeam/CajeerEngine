<?php

declare(strict_types=1);
namespace CajeerEngine\Search;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\RouteIndex;
use PDO;

/** The authoritative CMS catalog. External indexes are never trusted for public visibility. */
final class CmsSearchEngine implements SearchEngineInterface
{
    private RouteIndex $routes;
    private ContentEntryRepository $entries;
    public function __construct(private readonly string $root,private readonly DatabaseManager $db,private readonly string $configured='file')
    {$this->routes=new RouteIndex($root,$db);$this->entries=new ContentEntryRepository($root,$db);}
    private function database(): bool{return $this->entries->storage()==='database';}
    public function search(string $query,?string $index=null,int $limit=20): array{return $this->page($query,$index,$limit)['data'];}
    public function page(string $query,?string $index=null,int $limit=20,int $offset=0,?string $type=null,?string $locale=null): array
    {
        $tokens=SearchDocument::tokens($query);$limit=max(1,min(100,$limit));$offset=max(0,min(100000,$offset));
        if($type!==null&&$type!==''&&!preg_match('/^[a-z][a-z0-9_]{1,99}$/D',$type))throw new \InvalidArgumentException('Invalid content type filter');
        if($locale!==null&&$locale!==''&&!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$locale))throw new \InvalidArgumentException('Invalid locale filter');
        $meta=['query'=>$query,'index'=>$index?:'content','limit'=>$limit,'offset'=>$offset,'total'=>0,'type'=>$type??'','locale'=>$locale??'','driver'=>$this->driver(),'facets'=>['type'=>[],'locale'=>[]]];
        if(!$tokens||($index!==null&&$index!==''&&$index!=='content'))return ['data'=>[],'meta'=>$meta];
        if($this->database())return $this->databasePage($tokens,$type,$locale,$limit,$offset,$meta);
        $matches=[];
        foreach($this->routes->searchSnapshot() as $doc){
            if(($type!==null&&$type!==''&&$doc['type']!==$type)||($locale!==null&&$locale!==''&&$doc['locale']!==$locale))continue;
            $title=SearchDocument::lower($doc['title']);$body=SearchDocument::lower($doc['body']);$score=0;
            foreach($tokens as $token){if(str_contains($title,$token))$score+=5;elseif(str_contains($body,$token))$score+=1;else continue 2;}
            $matches[]=SearchDocument::result($doc,$score);foreach(['type','locale'] as $key)$meta['facets'][$key][$doc[$key]]=($meta['facets'][$key][$doc[$key]]??0)+1;
        }
        usort($matches,static fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($b['updated_at'],$a['updated_at'])?:strcmp($a['id'],$b['id']));
        $meta['total']=count($matches);return ['data'=>array_slice($matches,$offset,$limit),'meta'=>$meta];
    }
    private function databasePage(array $tokens,?string $type,?string $locale,int $limit,int $offset,array $meta): array
    {
        $this->routes->ensureSearch();
        return $this->db->transaction(function(PDO $pdo)use($tokens,$type,$locale,$limit,$offset,$meta){
            $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$pg=$driver==='pgsql';
            // Shared lock freezes publication generation across result/count/facet queries.
            if($driver==='pgsql'||$driver==='mysql')$pdo->query('SELECT generation FROM ce_public_route_state WHERE id=1 '.($pg?'FOR SHARE':'LOCK IN SHARE MODE'))->fetchColumn();
            else $pdo->query('SELECT generation FROM ce_public_route_state WHERE id=1')->fetchColumn();
            $where=[];$params=[];$rank=[];$prefix='';$from='ce_cms_search s';
            if($pg){
                $prefix="WITH query AS (SELECT plainto_tsquery('russian', :ru) || plainto_tsquery('english', :en) || plainto_tsquery('simple', :simple) AS term) ";
                $text=implode(' ',$tokens);$params=['ru'=>$text,'en'=>$text,'simple'=>$text];$from.=' CROSS JOIN query q';$where[]='s.search_vector @@ q.term';$score='ts_rank(s.search_vector,q.term)';
            }else{
                foreach($tokens as $i=>$token){$needle='%'.$token.'%';$where[]="(s.title_norm LIKE :t$i OR s.body_norm LIKE :b$i)";$params['t'.$i]=$needle;$params['b'.$i]=$needle;$rank[]="CASE WHEN s.title_norm LIKE :r$i THEN 5 ELSE 1 END";}
                $score=implode(' + ',$rank);
            }
            if($type!==null&&$type!==''){$where[]='s.content_type=:type';$params['type']=$type;}
            if($locale!==null&&$locale!==''){$where[]='s.locale=:locale';$params['locale']=$locale;}
            $base=' FROM '.$from.' WHERE '.implode(' AND ',$where);
            $run=static function(string $sql,array $bindings)use($pdo){$stmt=$pdo->prepare($sql);foreach($bindings as $k=>$v)$stmt->bindValue(':'.$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);$stmt->execute();return $stmt;};
            $meta['total']=(int)$run($prefix.'SELECT COUNT(*)'.$base,$params)->fetchColumn();
            foreach(['type'=>'content_type','locale'=>'locale'] as $key=>$column){$rows=$run($prefix.'SELECT s.'.$column.', COUNT(*)'.$base.' GROUP BY s.'.$column.' ORDER BY s.'.$column,$params)->fetchAll(PDO::FETCH_NUM);foreach($rows as [$value,$count])$meta['facets'][$key][$value]=(int)$count;}
            $bindings=$params+['lim'=>$limit,'off'=>$offset];if(!$pg)foreach($tokens as $i=>$token)$bindings['r'.$i]='%'.$token.'%';
            $rows=$run($prefix.'SELECT s.payload, '.$score.' AS score'.$base.' ORDER BY score DESC, s.document_id ASC LIMIT :lim OFFSET :off',$bindings)->fetchAll(PDO::FETCH_ASSOC);
            return ['data'=>array_map(static fn($row)=>SearchDocument::result(json_decode($row['payload'],true,flags:JSON_THROW_ON_ERROR),(float)$row['score']),$rows),'meta'=>$meta];
        });
    }
    public function rebuild(): array
    {
        $report=$this->routes->rebuild();return ['indexed'=>$this->diagnostics()['documents'],'types'=>count((new \CajeerEngine\Content\ContentTypeRepository($this->root,$this->db))->all()),'diagnostics'=>$this->diagnostics(),'routing'=>$report];
    }
    public function ensure(): array{$this->routes->ensureSearch();return $this->diagnostics();}
    public function index(string $index,string $id,array $document): void{throw new \LogicException('CMS search documents are derived from content; use repository mutations');}
    public function delete(string $index,string $id): void{throw new \LogicException('CMS search documents are derived from content; use repository mutations');}
    private function driver(): string{return $this->database()?($this->db->driver()==='pgsql'?'pgsql':$this->db->driver().'-sql'):'file';}
    public function diagnostics(): array
    {
        $configured=$this->configured;$result=['driver'=>'unavailable','configured_driver'=>$configured,'authoritative'=>true,'healthy'=>false,'documents'=>0,'indexes'=>[]];
        if(in_array($configured,['meili','meilisearch','opensearch'],true))$result['warning']='External search adapter is not authoritative in 1.2.2; CMS uses its local transactional catalog.';
        try{
            $result['driver']=$this->driver();
            if($this->database()){$pdo=$this->db->connection();$version=(int)$pdo->query('SELECT search_version FROM ce_public_route_state WHERE id=1')->fetchColumn();$count=(int)$pdo->query('SELECT COUNT(*) FROM ce_cms_search')->fetchColumn();$result['healthy']=$version===SearchProjection::VERSION;}
            else{$state=(new SearchProjection($this->root))->read();$count=count($state['documents']??[]);$result['healthy']=($state['version']??null)===SearchProjection::VERSION;$result['updated_at']=$state['updated_at']??null;}
            $result['documents']=$count;$result['indexes']=['content'=>$count];$result['writable']=$this->database()||is_writable(dirname((new SearchProjection($this->root))->path()));
        }catch(\Throwable $e){$result['error']='Search catalog unavailable; run migrate and search:index';}
        $result['ok']=$result['healthy'];return $result;
    }
}
