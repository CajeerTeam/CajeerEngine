<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

/** Bounded XMLReader scan: namespaces are checked, no DTD, no external entities. */
final class WxrDiscovery
{
    public const MAX_BYTES=32*1024*1024;
    public function scan(#[\SensitiveParameter] string $xml): array
    {
        if(strlen($xml)>self::MAX_BYTES||$xml==='')throw new \InvalidArgumentException('WXR must be 1 byte to 32 MiB.');
        if(str_contains($xml,"\0")||preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i',$xml))throw new \InvalidArgumentException('WXR DTD and entities are forbidden. Use UTF-8 WXR.');
        if(!class_exists(\XMLReader::class))throw new \RuntimeException('WXR discovery requires ext-xmlreader.');
        $old=libxml_use_internal_errors(true);libxml_clear_errors();$r=new \XMLReader();
        $counts=['posts'=>0,'pages'=>0,'media'=>0,'users'=>0,'comments'=>0,'categories'=>0,'tags'=>0];$types=[];$statuses=[];$fields=[];$version=null;$rss=false;$channel=false;$item=false;$type=null;$status=null;$itemDepth=-1;$warnings=[];
        try{
            if(!$r->XML($xml,null,LIBXML_NONET|LIBXML_COMPACT))throw new \InvalidArgumentException('Invalid WXR XML.');
            $r->setParserProperty(\XMLReader::LOADDTD,false);$r->setParserProperty(\XMLReader::SUBST_ENTITIES,false);$r->setParserProperty(\XMLReader::VALIDATE,false);
            while($r->read()){
                if($r->depth>64||$r->nodeType===\XMLReader::DOC_TYPE||$r->nodeType===\XMLReader::ENTITY_REF)throw new \InvalidArgumentException('Unsafe WXR XML.');
                if($r->nodeType===\XMLReader::END_ELEMENT&&$item&&$r->depth===$itemDepth&&$r->localName==='item'){
                    if($type===null)throw new \InvalidArgumentException('WXR item missing post type.');
                    $types[$type]=($types[$type]??0)+1;if($status!==null)$statuses[$status]=($statuses[$status]??0)+1;
                    $key=match($type){'post'=>'posts','page'=>'pages','attachment'=>'media',default=>null};if($key!==null)$counts[$key]++;$item=false;continue;
                }
                if($r->nodeType!==\XMLReader::ELEMENT)continue;
                $n=$r->localName;$d=$r->depth;$wp=preg_match('~^https?://wordpress\.org/export/[0-9.]+/$~D',$r->namespaceURI)===1;
                if($d===0&&$n==='rss')$rss=true;
                if($rss&&$d===1&&$n==='channel')$channel=true;
                if($channel&&$d===2&&$n==='item'&&$r->namespaceURI===''){if($r->isEmptyElement)throw new \InvalidArgumentException('Empty WXR item.');$item=true;$itemDepth=$d;$type=null;$status=null;continue;}
                if(!$wp||!$channel)continue;
                if(!$item&&$d===2){if($n==='wxr_version')$version=trim($r->readString());elseif($n==='author')$counts['users']++;elseif($n==='category')$counts['categories']++;elseif($n==='tag')$counts['tags']++;}
                if($item&&$d===$itemDepth+1){if($n==='post_type')self::readLabel($r,$type);elseif($n==='status')self::readLabel($r,$status);elseif($n==='comment')$counts['comments']++;}
                if($item&&$d===$itemDepth+2&&$n==='meta_key'&&count($fields)<101){$v=trim($r->readString());if(strlen($v)<=200&&$v!=='')$fields[$v]=$v;}
                if(count($types)>100||count($statuses)>100)throw new \InvalidArgumentException('WXR contains too many distinct types or statuses.');
            }
            if(libxml_get_errors()!==[]||!$rss||!$channel||!is_string($version)||!preg_match('/^1\.[012]$/D',$version)||$item)throw new \InvalidArgumentException('Invalid or unsupported WordPress WXR (1.0–1.2 required).');
        }finally{$r->close();libxml_clear_errors();libxml_use_internal_errors($old);}
        $warnings[]='WXR counts describe the export only. Author records are not a full user database; media entries do not contain file bytes. Custom types and plugin metadata require mapping.';
        return SourceDiscovery::report('wordpress-wxr',$counts,$types,$statuses,array_values($fields),$warnings,['version'=>'WXR '.$version,'sha256'=>hash('sha256',$xml),'bytes'=>strlen($xml)]);
    }
    private static function readLabel(\XMLReader $reader, ?string &$target): void
    {
        $target = self::label($reader->readString());
    }

    private static function label(string $v):string{ $v=trim($v);if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$v))throw new \InvalidArgumentException('Invalid WXR type or status.');return $v; }
}
