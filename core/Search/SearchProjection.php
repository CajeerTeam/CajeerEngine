<?php

declare(strict_types=1);
namespace CajeerEngine\Search;

use PDO;
use CajeerEngine\Support\JsonFile;

/** Derived data is committed by RouteIndex under its existing writer lock/SQL transaction. */
final class SearchProjection
{
    public const VERSION=2;
    public function __construct(private readonly string $root){}
    public function path(): string{return $this->root.'/storage/app/search-index.json';}
    public function read(): array{return (new JsonFile($this->path()))->readObject([]);}
    public function write(array $docs,string $fingerprint,bool $repair=false): void
    {
        try{$old=$this->read();}catch(\RuntimeException $e){if(!$repair)throw $e;$old=[];} // Explicit rebuild can replace a damaged derived index.
        if(($old['version']??null)===self::VERSION&&($old['fingerprint']??null)===$fingerprint&&($old['documents']??null)===$docs)return;
        (new JsonFile($this->path()))->writeObject(['version'=>self::VERSION,'fingerprint'=>$fingerprint,'documents'=>$docs,'updated_at'=>gmdate(DATE_ATOM)]);
    }
    public function synchronize(PDO $pdo,array $docs,bool $force=false,?array $onlyIds=null): void
    {
        if($onlyIds===null)$old=$pdo->query('SELECT document_id, digest FROM ce_cms_search')->fetchAll(PDO::FETCH_KEY_PAIR);
        else {
            if(!$onlyIds)return;
            if(array_diff(array_keys($docs),$onlyIds))throw new \LogicException('Projection scope mismatch');
            $s=$pdo->prepare('SELECT document_id,digest FROM ce_cms_search WHERE document_id IN ('.implode(',',array_fill(0,count($onlyIds),'?')).')');$s->execute($onlyIds);$old=$s->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $delete=$pdo->prepare('DELETE FROM ce_cms_search WHERE document_id = :id');
        $pg=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql';
        $sql='INSERT INTO ce_cms_search (document_id, entry_id, content_type, locale, title, body, title_norm, body_norm, payload, digest'.($pg?', search_vector':'').') VALUES (:id,:entry,:type,:locale,:title,:body,:tn,:bn,:payload,:digest'.($pg?", setweight(to_tsvector(CAST(:lang1 AS regconfig), :ts_title),'A') || setweight(to_tsvector(CAST(:lang2 AS regconfig), :ts_body),'B')":'').')';
        $insert=$pdo->prepare($sql);
        foreach($old as $id=>$digest)if(!isset($docs[$id]))$delete->execute(['id'=>$id]);
        foreach($docs as $id=>$doc){
            $payload=json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$digest=hash('sha256',$payload);
            if(!$force&&($old[$id]??null)===$digest)continue;
            if(isset($old[$id]))$delete->execute(['id'=>$id]);
            $params=['id'=>$id,'entry'=>$doc['source_id'],'type'=>$doc['type'],'locale'=>$doc['locale'],'title'=>$doc['title'],'body'=>$doc['body'],'tn'=>SearchDocument::lower($doc['title']),'bn'=>SearchDocument::lower($doc['body']),'payload'=>$payload,'digest'=>$digest];
            if($pg){$lang=match(strtolower(explode('-',$doc['locale'])[0])){'ru'=>'russian','en'=>'english',default=>'simple'};$params+=['lang1'=>$lang,'lang2'=>$lang,'ts_title'=>$doc['title'],'ts_body'=>$doc['body']];}
            $insert->execute($params);
        }
        $pdo->exec('UPDATE ce_public_route_state SET search_version = '.self::VERSION.' WHERE id = 1');
    }
}
