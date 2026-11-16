<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Security\SafeHtml;

final class DleContent
{
    public static function html(string $text):string
    {
        if(!class_exists(\DOMDocument::class))throw new \RuntimeException('PHP DOM extension is required for DLE richtext planning.');
        // Convert only literal, balanced formatting tags; no PHP, plugin shortcodes or arbitrary attributes.
        $tags=['b'=>'strong','i'=>'em','u'=>'u','s'=>'s','quote'=>'blockquote','code'=>'pre'];
        foreach($tags as $bb=>$html){$text=preg_replace('~\['.$bb.'\](.*?)\[/'.$bb.'\]~is','<'.$html.'>$1</'.$html.'>',$text)??throw new \RuntimeException('DLE markup conversion failed.');}
        return SafeHtml::sanitize($text);
    }
}
