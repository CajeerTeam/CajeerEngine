<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Security\SafeHtml;

/** WordPress content is data, never a source of executable templates or shortcodes. */
final class WordPressContent
{
    public static function html(string $html):string
    {
        if(!class_exists(\DOMDocument::class)&&preg_match('/[<&]/',$html))throw new \RuntimeException('PHP DOM extension is required for WordPress richtext planning.');
        return SafeHtml::sanitize($html);
    }
    public static function date(mixed $raw):?string
    {
        if(!is_string($raw))return null;
        if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z?$/D',$raw))$raw=str_replace('T',' ',rtrim($raw,'Z'));
        return ContentMigrationImporter::date($raw);
    }
    public static function record(string $type,array $row,array $meta=[]):array
    {
        $protected=($row['protected']??false)===true||($row['password']??'')!=='';
        $gmt=self::date($row['date_gmt']??'');
        if($protected||($gmt!==null&&$gmt>gmdate('Y-m-d H:i:s')))$row['status']='draft';
        // Source metadata cannot override these trusted import controls.
        $meta['meta:wp_date_gmt']=$gmt??'';
        $meta['meta:wp_protected']=$protected;
        $record=PlanningSource::record($type,$row,$meta);$record['origin']='wordpress';
        return $record;
    }
}
