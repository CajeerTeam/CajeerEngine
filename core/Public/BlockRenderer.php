<?php

declare(strict_types=1);
namespace CajeerEngine\Public;

use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Security\SafeHtml;

/** Render stored block data, never executable markup from an editor. */
final class BlockRenderer
{
    private array $media = [];
    public function __construct(private readonly MediaRepository $repository) {}
    public function references(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $v) $this->references($v); return; }
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $value) || array_key_exists($value,$this->media)) return;
        $row=$this->repository->find($value);
        $this->media[$value]=$row && ($row['visibility']??'public')==='public' ? $row : null;
    }
    public function media(): array { return array_filter($this->media); }
    public function imageUrl(string $id): string
    {
        $this->references($id); $row=$this->media[$id]??null;
        return $row ? SafeHtml::url((string)$row['url']) : '';
    }
    private function e(mixed $v): string { return htmlspecialchars(is_scalar($v)?(string)$v:'', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private function image(mixed $id): string
    {
        if(!is_string($id))return '';
        $this->references($id); $m=$this->media[$id]??null;
        if(!$m || !str_starts_with($m['mime_type']??'', 'image/'))return '';
        $url=SafeHtml::url((string)($m['variants']['medium']['url']??$m['url']));
        if($url==='')return '';
        return '<figure><img loading="lazy" decoding="async" src="'.$this->e($url).'" alt="'.$this->e($m['alt']??'').'"'.(!empty($m['width'])&&!empty($m['height'])?' width="'.(int)$m['width'].'" height="'.(int)$m['height'].'"':'').'>'.(!empty($m['caption'])?'<figcaption>'.$this->e($m['caption']).'</figcaption>':'').'</figure>';
    }
    public function render(array $blocks): string
    {
        $html='';
        foreach($blocks as $block){
            if(!is_array($block))continue;
            $handle=(string)($block['handle']??$block['type']??'text');
            $d=is_array($block['data']??null)?$block['data']:$block;
            $title=$this->e($d['title']??'');
            $body=SafeHtml::sanitize(is_string($d['content']??null)?$d['content']:'');
            $heading=$title!==''?'<h2>'.$title.'</h2>':'';
            $link=SafeHtml::url(is_string($d['button_url']??null)?$d['button_url']:'');
            $button=$link!==''&&!empty($d['button_label'])?'<a class="ce-button" href="'.$this->e($link).'">'.$this->e($d['button_label']).'</a>':'';
            $inner=match($handle){
                'hero'=>$heading.$body.$this->image($d['image']??null).$button,
                'cta'=>$heading.$body.$button,
                'image'=>$heading.$this->image($d['image']??null),
                'gallery'=>$heading.'<div class="ce-gallery">'.implode('',array_map(fn($id)=>$this->image($id),(array)($d['images']??[]))).'</div>',
                'columns'=>$heading.'<div class="ce-columns"><div>'.SafeHtml::sanitize((string)($d['left']??'')).'</div><div>'.SafeHtml::sanitize((string)($d['right']??'')).'</div></div>',
                'text','richtext'=>$heading.$body,
                default=>$heading.$body, // custom blocks may use their own theme template; never expose arbitrary JSON.
            };
            if($inner!=='')$html.='<section class="ce-block ce-block-'.$this->e($handle).'">'.$inner.'</section>';
        }
        return $html;
    }
}
