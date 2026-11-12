<?php

declare(strict_types=1);
namespace CajeerEngine\Media;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Cms\NavigationRepository;

/** Conservative reference scanner: exact IDs and URLs embedded in rich text, including revision history. */
final readonly class MediaUsage
{
    public function __construct(private string $root) {}
    public function find(array $item): array
    {
        $needles=[$item['id'],$item['url'],'/uploads/'.$item['path']];
        foreach(array_merge(array_values($item['variants']??[]),$item['retired_variants']??[]) as $v){$needles[]=$v['url'];$needles[]='/uploads/'.$v['path'];}
        $hits=[];$types=new ContentTypeRepository($this->root);$entries=new ContentEntryRepository($this->root);
        foreach($types->all() as $type)foreach($entries->list($type['handle'],['status'=>'all']) as $e){
            if($this->matches($e['data']??[],$needles))$hits[]=['kind'=>'content','type'=>$type['handle'],'id'=>$e['id'],'title'=>$e['title']];
            foreach($entries->revisions($type['handle'],$e['id']) as $revision)if($this->matches($revision['data']??$revision,$needles))$hits[]=['kind'=>'revision','type'=>$type['handle'],'id'=>$e['id'],'revision'=>$revision['revision']??null];
        }
        if($this->matches((new ThemeConfigRepository($this->root))->get(),$needles))$hits[]=['kind'=>'theme'];
        foreach((new NavigationRepository($this->root))->all() as $menu)if($this->matches($menu,$needles))$hits[]=['kind'=>'navigation','id'=>$menu['id']??$menu['handle']];
        return $hits;
    }
    private function matches(mixed $value,array $needles): bool
    {
        if(is_array($value)){foreach($value as $v)if($this->matches($v,$needles))return true;return false;}
        if(!is_string($value))return false;
        foreach($needles as $n)if($n!==''&&str_contains($value,$n))return true;return false;
    }
}
