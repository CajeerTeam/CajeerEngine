<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

/** Builds a deterministic route graph. Parent paths are authoritative for child pages. */
final class RouteGraph
{
    public static function build(array $entries,array $settings,array $previous=[], ?string $previewId=null): array
    {
        $default=$settings['locale']??'ru';$prefix=($settings['locale_strategy']??'none')==='prefix';
        $enabled=$settings['enabled_locales']??[]; $byId=[];$homes=[];$explicit=[];
        foreach($entries as $e){
            $id=(string)$e['id']; if(isset($byId[$id])) throw new \DomainException('Duplicate content ID: '.$id);
            $byId[$id]=$e; $d=$e['data']??[];$locale=$e['locale']??$default;
            if($e['type']==='pages'&&empty($d['parent_id'])){
                if(($d['path']??null)==='/') $explicit[$locale][]=$id;
                if($e['slug']===($settings['home_slug']??'home')) $homes[$locale][]=$id;
            }
        }
        $home=[];foreach(array_unique(array_merge(array_keys($homes),array_keys($explicit))) as $l){
            $explicitPublished=array_values(array_filter($explicit[$l]??[],fn($id)=>$byId[$id]['status']==='published'));
            $namedPublished=array_values(array_filter($homes[$l]??[],fn($id)=>$byId[$id]['status']==='published'));
            $pub=$explicitPublished?:$namedPublished;
            if(count($pub)>1)throw new \DomainException('Multiple published home pages for '.$l);
            $home[$l]=$pub[0]??$explicit[$l][0]??$homes[$l][0]??null;
        }
        $memo=[];$visiting=[];
        $walk=function(string $id,int $depth=0)use(&$walk,&$memo,&$visiting,$byId,$home,$default,$prefix,$enabled):array{
            if(isset($memo[$id]))return $memo[$id];
            if($depth>128||isset($visiting[$id]))throw new \DomainException('Page tree contains a cycle or exceeds 128 levels');
            $visiting[$id]=true;$e=$byId[$id];$d=$e['data']??[];$locale=$e['locale']??$default;
            $parent=$e['type']==='pages'?($d['parent_id']??null):null;
            $visible=$e['status']==='published';
            if($prefix&&!in_array($locale,$enabled,true))$visible=false;
            if($parent!==null&&$parent!==''){
                if(!is_string($parent)||!isset($byId[$parent])||$byId[$parent]['type']!=='pages'||$byId[$parent]['locale']!==$locale)throw new \DomainException('Invalid page parent for '.$id);
                $p=$walk($parent,$depth+1);$path=rtrim($p['local'],'/').'/'.$e['slug'];$visible=$visible&&$p['visible'];
            }elseif($e['type']==='pages'&&($home[$locale]??null)===$id&&($prefix||$locale===$default))$path='/';
            else $path=(string)($d['path']??$d['_path']??'')?:'/'.$e['slug'];
            $path=PublicPath::content($path);
            if($prefix&&in_array(rawurldecode(explode('/',ltrim($path,'/'))[0]),$enabled,true))throw new \DomainException('Locale prefix is reserved in page paths: '.$path);
            $url=$prefix&&$locale!==$default?'/'.$locale.($path==='/'?'':$path):$path;
            $url=PublicPath::content($url);
            unset($visiting[$id]);return $memo[$id]=['local'=>$path,'path'=>$url,'visible'=>$visible];
        };
        if($previewId!==null) return isset($byId[$previewId])?$walk($previewId):[];
        $routes=[];$ids=[];
        foreach($byId as $id=>$e){
            $p=$walk($id);if(!$p['visible'])continue;$path=$p['path'];
            if(isset($routes[$path]))throw new \DomainException('Public route collision: '.$path);
            $d=$e['data']??[];
            $routes[$path]=['kind'=>'page','path'=>$path,'id'=>$id,'type'=>$e['type'],'title'=>$e['title'],'slug'=>$e['slug'],'locale'=>$e['locale'],'updated_at'=>$e['updated_at']??'','noindex'=>filter_var($d['seo_noindex']??false,FILTER_VALIDATE_BOOLEAN),'canonical'=>(string)($d['canonical_url']??''),'translation_group'=>$e['translation_group']??$id];
            $ids[$id]=$path;
        }
        // Historical aliases always point to the current canonical path, never another alias.
        foreach($previous as $old){$path=$old['path'];$id=$old['id'];if(isset($routes[$path])||!isset($ids[$id])||$ids[$id]===$path||$path==='/'||PublicPath::reserved($path))continue;$routes[$path]=['kind'=>'alias','path'=>$path,'id'=>$id,'type'=>$old['type'],'target'=>$ids[$id]];}
        ksort($routes,SORT_STRING);return $routes;
    }
}
