<?php

declare(strict_types=1);
namespace CajeerEngine\Search;

/** Server-rendered, script-free search UI; all values are escaped, pagination preserves filters. */
final class PublicSearchPage
{
    public static function render(array $result,string $siteName='CajeerEngine'): string
    {
        $meta=$result['meta'];$q=$meta['query'];$type=$meta['type']??'';$locale=$meta['locale']??'';$limit=$meta['limit'];$offset=$meta['offset'];$total=$meta['total'];
        $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,follow"><title>Поиск — '.$e($siteName).'</title><style>body{font:1rem/1.6 system-ui,sans-serif;max-width:52rem;margin:auto;padding:1.5rem;color:#172033}a{color:#174ea6}form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}label{display:flex;flex-direction:column}input,button{font:inherit;padding:.5rem}input[type=search]{width:min(28rem,75vw)}article{padding:1rem 0;border-bottom:1px solid #ddd}article h2{margin:0;font-size:1.2rem}nav{display:flex;gap:1rem;margin-top:1.5rem}small{color:#475569}</style></head><body><header><a href="/">'.$e($siteName).'</a></header><main><h1>Поиск по сайту</h1><form method="get" action="/search"><label>Запрос<input type="search" name="q" maxlength="256" value="'.$e($q).'"></label><label>Язык<input name="locale" value="'.$e($locale).'" placeholder="ru / en" maxlength="32"></label><label>Тип<input name="type" value="'.$e($type).'" placeholder="pages" maxlength="100"></label><button type="submit">Найти</button></form>';
        if(trim($q)==='')$html.='<p>Введите поисковый запрос.</p>';
        else{$html.='<p role="status">Найдено: '.$total.'</p>';if(!$total)$html.='<p>Ничего не найдено. Попробуйте другой запрос.</p>';elseif(!$result['data'])$html.='<p>На этой странице нет результатов.</p>';
            foreach($result['data'] as $r)$html.='<article><h2><a href="'.$e($r['url']).'">'.$e($r['title']).'</a></h2><small>'.$e($r['type']).' · '.$e($r['locale']).'</small><p>'.$e($r['excerpt']).'</p></article>';
            $link=static fn($start)=>'/search?'.http_build_query(['q'=>$q,'type'=>$type,'locale'=>$locale,'limit'=>$limit,'offset'=>$start],'','&',PHP_QUERY_RFC3986);
            $html.='<nav aria-label="Страницы результатов">';if($offset>0)$html.='<a href="'.$e($link(max(0,$offset-$limit))).'">Назад</a>';if($offset+$limit<$total)$html.='<a href="'.$e($link($offset+$limit)).'">Далее</a>';$html.='</nav>';
        }
        return $html.'</main></body></html>';
    }
}
