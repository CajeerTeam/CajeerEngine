<?php

declare(strict_types=1);
namespace CajeerEngine\Search;

/** A public search projection: never serialize raw content or private relations. */
final class SearchDocument
{
    public static function text(string $value): string
    {
        $value=preg_replace('~<(script|style|template)\b[^>]*>.*?</\1\s*>~isu',' ',$value)??'';
        $value=html_entity_decode(strip_tags(preg_replace('/<[^>]+>/u',' $0 ',$value)??$value),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return trim(preg_replace('/\s+/u',' ',$value)??'');
    }
    public static function lower(string $value): string
    {
        if(function_exists('mb_strtolower'))return mb_strtolower($value,'UTF-8');
        return strtr(strtolower($value),array_combine(preg_split('//u','АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',-1,PREG_SPLIT_NO_EMPTY),preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY)));
    }
    public static function tokens(string $q): array
    {
        if(strlen($q)>512||!preg_match('//u',$q))throw new \InvalidArgumentException('Search query must be valid UTF-8 and at most 512 bytes');
        $tokens=array_values(array_unique(preg_split('/[^\p{L}\p{N}]+/u',self::lower($q),-1,PREG_SPLIT_NO_EMPTY)?:[]));
        if(count($tokens)>12)throw new \InvalidArgumentException('Search query supports at most 12 distinct terms');
        return $tokens;
    }
    private static function truncate(string $v,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($v,0,$length,'UTF-8');
        if(strlen($v)<=$length)return $v;
        $bytes=substr($v,0,$length*4);
        while($bytes!==''&&!preg_match('//u',$bytes))$bytes=substr($bytes,0,-1);
        return implode('',array_slice(preg_split('//u',$bytes,-1,PREG_SPLIT_NO_EMPTY)?:[],0,$length));
    }
    private static function fields(array $schema,array $data,int $depth=0): array
    {
        if($depth>12)return [];$parts=[];
        foreach($schema['fields']??[] as $field){
            $name=$field['handle'];$value=$data[$name]??null;
            // Do not index IDs, arbitrary JSON, paths, credentials, hidden or explicitly non-searchable fields.
            if(($field['settings']['searchable']??true)===false||($field['settings']['private']??false)||preg_match('/(?:^|_)(?:secret|password|token|private|internal|key|url|path|id)(?:_|$)/i',$name))continue;
            if(in_array($field['type'],['text','textarea','richtext','markdown'],true)&&is_string($value))$parts[]=self::text($value);
            elseif($field['type']==='blocks'&&is_array($value)){
                $defs=array_column($schema['blocks']??[],null,'handle');
                foreach($value as $block)if(is_array($block)&&isset($defs[$block['handle']??''])&&is_array($block['data']??null))array_push($parts,...self::fields($defs[$block['handle']],$block['data'],$depth+1));
            }
        }
        return $parts;
    }
    public static function project(array $routes,array $entries,array $types,array $theme): array
    {
        $byId=array_column($entries,null,'id');$schemas=array_column($types,null,'handle');$docs=[];
        foreach($routes as $route){
            if($route['kind']!=='page'||($route['noindex']??false)||in_array($route['path'],[$theme['error_404_path']??'', $theme['error_410_path']??''],true))continue;
            $entry=$byId[$route['id']]??null;if(!$entry||$entry['status']!=='published')continue;
            $title=self::truncate(self::text($entry['title']),500);
            $body=self::truncate(implode(' ',self::fields($schemas[$entry['type']]??[],$entry['data']??[])),50000);
            $id=$entry['type'].':'.$entry['id'];
            $docs[$id]=['id'=>$id,'source_id'=>$entry['id'],'index'=>'content','type'=>$entry['type'],'title'=>$title,'body'=>$body,'excerpt'=>self::truncate($body?:$title,280),'url'=>$route['path'],'locale'=>$entry['locale'],'status'=>'published','revision'=>(int)($entry['revision']??1),'updated_at'=>$entry['updated_at']??''];
        }
        ksort($docs);return $docs;
    }
    public static function result(array $doc,float $score): array
    {
        return array_intersect_key($doc,array_flip(['id','source_id','index','type','title','excerpt','url','locale','status','revision','updated_at']))+['score'=>$score];
    }
}
