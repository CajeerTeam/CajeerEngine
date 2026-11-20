<?php

declare(strict_types=1);
namespace CajeerEngine\Migration;

/** Attachment IDs and featured-image references only. No media bytes or executable WP data. */
final class WordPressMediaMetadata
{
    public static function fromWxr(#[\SensitiveParameter] string $xml):array
    {
        (new WxrDiscovery())->scan($xml);
        $reader=new \XMLReader();if(!$reader->XML($xml,null,LIBXML_NONET|LIBXML_COMPACT))throw new \InvalidArgumentException('Invalid WXR export.');
        $reader->setParserProperty(\XMLReader::LOADDTD,false);$reader->setParserProperty(\XMLReader::SUBST_ENTITIES,false);
        $attachments=[];$featured=[];$item=null;$pair=null;
        try{
            while($reader->read()){
                $d=$reader->depth;$n=$reader->localName;$wp=str_contains($reader->namespaceURI,'wordpress.org/export/');
                if($reader->nodeType===\XMLReader::ELEMENT){
                    if($d===2&&$n==='item'&&$reader->namespaceURI===''){$item=['post_id'=>null,'post_type'=>null,'attachment_url'=>null,'_thumbnail_id'=>null,'_wp_attached_file'=>null];continue;}
                    if($item===null)continue;
                    if($d===3&&$wp&&in_array($n,['post_id','post_type','attachment_url'],true)){$item[$n]=$reader->readString();continue;}
                    if($d===3&&$wp&&$n==='postmeta'){$pair=[];continue;}
                    if($d===4&&$wp&&$pair!==null&&in_array($n,['meta_key','meta_value'],true)){$pair[$n]=$reader->readString();continue;}
                }
                if($reader->nodeType===\XMLReader::END_ELEMENT){
                    if($d===3&&$wp&&$n==='postmeta'&&$pair!==null){$key=$pair['meta_key']??'';if(in_array($key,['_thumbnail_id','_wp_attached_file'],true))$item[$key]=$pair['meta_value']??'';$pair=null;}
                    if($d===2&&$n==='item'&&$item!==null){
                        $id=(string)($item['post_id']??'');
                        if(ctype_digit($id)&&$id!=='0'){
                            if(($item['post_type']??'')==='attachment'){$url=(string)(self::metadata($item, '_wp_attached_file') ?: self::metadata($item, 'attachment_url'));if($url!=='')$attachments[$id]=$url;}
                            else { $thumbnail=self::metadata($item, '_thumbnail_id');if(ctype_digit($thumbnail)&&$thumbnail!=='0')$featured[$id]=$thumbnail; }
                        }
                        $item=null;
                    }
                }
            }
        }finally{$reader->close();}
        if(count($attachments)>20000||count($featured)>20000)throw new \RuntimeException('WordPress attachment metadata limit exceeded.');
        return ['attachments'=>$attachments,'featured'=>$featured];
    }
    private static function metadata(array $item, string $name): string
    {
        return is_string($item[$name] ?? null) ? $item[$name] : '';
    }

    public static function fromDatabase(\PDO $pdo,string $prefix):array
    {
        $p=$prefix===''?'':SourceDiscovery::identifier($prefix);
        $posts='`'.SourceDiscovery::identifier($p.'posts').'`';$meta='`'.SourceDiscovery::identifier($p.'postmeta').'`';
        $attachments=[];$featured=[];
        $q=$pdo->query("SELECT ID,guid FROM $posts WHERE post_type='attachment' ORDER BY ID LIMIT 20001");
        foreach($q as $r){$attachments[(string)$r['ID']]=(string)($r['guid']??'');}
        if(count($attachments)>20000)throw new \RuntimeException('WordPress attachment limit exceeded.');
        $q=$pdo->query("SELECT post_id,meta_key,meta_value FROM $meta WHERE meta_key IN ('_wp_attached_file','_thumbnail_id') ORDER BY meta_id LIMIT 40001");
        $count=0;foreach($q as $r){if(++$count>40000)throw new \RuntimeException('WordPress media metadata limit exceeded.');$id=(string)$r['post_id'];$v=(string)$r['meta_value'];if($r['meta_key']==='_wp_attached_file'&&isset($attachments[$id]))$attachments[$id]=$v;elseif($r['meta_key']==='_thumbnail_id'&&ctype_digit($v))$featured[$id]=$v;}
        return ['attachments'=>$attachments,'featured'=>$featured];
    }
    public static function relative(string $path):?string
    {
        $p=parse_url($path);if($p===false)return null;
        $path=(string)($p['path']??'');
        foreach(['/wp-content/uploads/','/uploads/'] as $needle){$at=strpos($path,$needle);if($at!==false){$value=rawurldecode(substr($path,$at+strlen($needle)));return MediaMigration::safeRelative($value)?$value:null;}}
        $path=rawurldecode(ltrim($path,'/'));
        return MediaMigration::safeRelative($path)?$path:null;
    }
}
