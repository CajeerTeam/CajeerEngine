<?php

declare(strict_types=1);
namespace CajeerEngine\Security;

/** Reconstruct only allowed HTML; never serialize untrusted DOM attributes or namespaces. */
final class SafeHtml
{
    public static function url(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_starts_with($url,'//')) return '';
        if (str_starts_with($url,'/') || str_starts_with($url,'#')) return $url;
        $parts = parse_url($url);
        if (is_array($parts) && in_array(strtolower($parts['scheme']??''),['http','https'],true) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])) return $url;
        return '';
    }

    public static function sanitize(string $html): string
    {
        if (!class_exists(\DOMDocument::class)) return self::escape($html);
        $doc=new \DOMDocument();
        $previous=libxml_use_internal_errors(true);
        try { $loaded=$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); }
        finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
        if (!$loaded) return self::escape($html);
        $body=$doc->getElementsByTagName('body')->item(0);
        if (!$body) return '';
        $out='';foreach($body->childNodes as $node)$out.=self::node($node,0);
        return $out;
    }
    private static function node(\DOMNode $node,int $depth): string
    {
        if ($depth>64) return '';
        if ($node instanceof \DOMText) return self::escape($node->nodeValue??'');
        if (!$node instanceof \DOMElement) return '';
        $tag=strtolower($node->tagName);
        if(in_array($tag,['script','style','iframe','object','embed','svg','math','template','noscript','form','textarea'],true))return '';
        $allowed=['p','div','span','br','hr','h1','h2','h3','h4','h5','h6','strong','b','em','i','u','s','blockquote','pre','code','ul','ol','li','dl','dt','dd','table','thead','tbody','tr','td','th','caption','figure','figcaption','a','img'];
        $children='';foreach($node->childNodes as $child)$children.=self::node($child,$depth+1);
        if(!in_array($tag,$allowed,true))return $children;
        $attrs='';
        if($tag==='a'||$tag==='img'){
            $name=$tag==='a'?'href':'src';$url=self::url($node->getAttribute($name));
            if($url!=='')$attrs.=' '.$name.'="'.self::escape($url).'"';
            if($tag==='a')$attrs.=' rel="noopener noreferrer"';
            if($tag==='img')$attrs.=' alt="'.self::escape($node->getAttribute('alt')).'" loading="lazy"';
        }
        if($node->hasAttribute('title'))$attrs.=' title="'.self::escape($node->getAttribute('title')).'"';
        return '<'.$tag.$attrs.'>'.(in_array($tag,['br','hr','img'],true)?'':$children.'</'.$tag.'>');
    }
    private static function escape(string $text): string {return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
