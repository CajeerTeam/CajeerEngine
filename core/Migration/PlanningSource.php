<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Security\{OutboundHttpTransport,PublicHttpClient};

/** Bounded inventory: fail closed instead of approving a truncated migration. */
final readonly class PlanningSource
{
    public const LIMIT=500;
    public const MAX_BYTES=16*1024*1024;
    public function __construct(private OutboundHttpTransport $http=new PublicHttpClient()){}
    public static function inventory(array $records,array $users,array $warnings=[]):array
    {
        if(count($records)>self::LIMIT||count($users)>self::LIMIT)throw new \RuntimeException('Planning limit: 500 content records and 500 users. Split the source export.');
        $seen=[];foreach($records as $r){$key=$r['type'].':'.$r['id'];if($r['id']===''||isset($seen[$key]))throw new \RuntimeException('Missing or duplicate source record ID.');$seen[$key]=true;}
        $seen=[];foreach($users as $u){if($u['id']===''||isset($seen[$u['id']]))throw new \RuntimeException('Missing or duplicate source user ID.');$seen[$u['id']]=true;}
        usort($records,fn($a,$b)=>strcmp($a['type'].':'.$a['id'],$b['type'].':'.$b['id']));usort($users,fn($a,$b)=>strcmp($a['id'],$b['id']));
        $result=compact('records','users','warnings');if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>self::MAX_BYTES)throw new \RuntimeException('Planning inventory exceeds 16 MiB.');return $result;
    }
    public static function record(string $type,array $r,array $meta=[]):array
    {
        return ['id'=>(string)($r['id']??''),'type'=>$type,'status'=>(string)($r['status']??'draft'),'author'=>(string)($r['author']??''),'fields'=>['title'=>(string)($r['title']??''),'body'=>(string)($r['body']??''),'excerpt'=>(string)($r['excerpt']??''),'slug'=>(string)($r['slug']??''),'date'=>(string)($r['date']??''),'categories'=>(string)($r['categories']??''),'tags'=>(string)($r['tags']??'')]+$meta];
    }
    public function database(string $source,\PDO $pdo,array $report):array
    {
        $wp=$source==='wordpress-db';$prefix=$report['details']['prefix'];$schema=$report['details']['columns'];$records=[];$users=[];$warnings=[];
        $required=$wp?['posts'=>['ID','post_type','post_status','post_title','post_content','post_name'],'postmeta'=>['post_id','meta_id','meta_key','meta_value']]:['post'=>['id','title','full_story']];foreach($required as $suffix=>$cols)if(array_diff($cols,$schema[$prefix.$suffix]??[]))throw new \RuntimeException('Source schema lacks required planning columns: '.$suffix);
        $table=fn($s)=>'`'.SourceDiscovery::identifier($prefix.$s).'`';
        $read=function($s,$mapping,$where='',$order='')use($pdo,$schema,$prefix,$table){
            if(!isset($schema[$prefix.$s]))return [];$cols=$schema[$prefix.$s];$select=[];$sizes=[];foreach($mapping as $alias=>$col){$expr=in_array($col,$cols,true)?'`'.SourceDiscovery::identifier($col).'`':"''";$select[]=$expr.' AS `'.SourceDiscovery::identifier($alias).'`';$sizes[]='COALESCE(OCTET_LENGTH('.$expr.'),0)';}
            $size=$pdo->query('SELECT COALESCE(SUM(n),0) FROM (SELECT '.implode('+',$sizes).' AS n FROM '.$table($s).$where.$order.' LIMIT '.(self::LIMIT+1).') AS bounded')->fetchColumn();if((float)$size>self::MAX_BYTES)throw new \RuntimeException('Source collection exceeds planning byte limit.');
            return $pdo->query('SELECT '.implode(',',$select).' FROM '.$table($s).$where.$order.' LIMIT '.(self::LIMIT+1))->fetchAll(\PDO::FETCH_ASSOC);
        };
        if($wp){
            $rows=$read('posts',['id'=>'ID','type'=>'post_type','status'=>'post_status','author'=>'post_author','title'=>'post_title','body'=>'post_content','excerpt'=>'post_excerpt','slug'=>'post_name','date'=>'post_date']," WHERE post_type NOT IN ('attachment','revision','nav_menu_item','customize_changeset','wp_global_styles','wp_template','wp_template_part','wp_navigation','oembed_cache')",' ORDER BY ID');
            if(count($rows)>self::LIMIT)throw new \RuntimeException('Content exceeds planning limit (500).');
            $meta=[];if($rows){$ids=array_map(fn($r)=>(string)(int)$r['id'],$rows);$metaSize=$pdo->query('SELECT COALESCE(SUM(OCTET_LENGTH(meta_value)+OCTET_LENGTH(meta_key)),0) FROM '.$table('postmeta').' WHERE post_id IN ('.implode(',',$ids).')')->fetchColumn();if((float)$metaSize>self::MAX_BYTES)throw new \RuntimeException('WordPress metadata exceeds planning byte limit.');$q=$pdo->query('SELECT post_id,meta_key,CASE WHEN OCTET_LENGTH(meta_value)>1048576 THEN NULL ELSE meta_value END AS meta_value,OCTET_LENGTH(meta_value) AS value_bytes FROM '.$table('postmeta').' WHERE post_id IN ('.implode(',',$ids).') ORDER BY meta_id LIMIT 10001');$n=0;$bytes=0;while($m=$q->fetch(\PDO::FETCH_ASSOC)){if(++$n>10000||(int)$m['value_bytes']>1048576||($bytes+=(int)$m['value_bytes'])>self::MAX_BYTES)throw new \RuntimeException('WordPress metadata exceeds planning limit.');$key='meta:'.$m['meta_key'];if(isset($meta[$m['post_id']][$key])){$warnings['duplicate_meta']='Repeated WordPress meta keys use their first value; inspect before import.';continue;}$meta[$m['post_id']][$key]=(string)$m['meta_value'];}}
            $tax=[];if($rows&&isset($schema[$prefix.'term_relationships'],$schema[$prefix.'term_taxonomy'],$schema[$prefix.'terms'])){$q=$pdo->query('SELECT r.object_id,t.taxonomy,n.slug FROM '.$table('term_relationships').' r JOIN '.$table('term_taxonomy').' t ON t.term_taxonomy_id=r.term_taxonomy_id JOIN '.$table('terms').' n ON n.term_id=t.term_id WHERE r.object_id IN ('.implode(',',$ids).") AND t.taxonomy IN ('category','post_tag') ORDER BY r.object_id,t.taxonomy,n.slug LIMIT 10001");$n=0;while($v=$q->fetch(\PDO::FETCH_ASSOC)){if(++$n>10000)throw new \RuntimeException('Taxonomy relationship limit exceeded.');$tax[$v['object_id']][$v['taxonomy']][]=$v['slug'];}}else $warnings['taxonomy']='WordPress database taxonomy relations unavailable.';
            foreach($rows as $r){$r['categories']=implode(',',$tax[$r['id']]['category']??[]);$r['tags']=implode(',',$tax[$r['id']]['post_tag']??[]);$records[]=self::record((string)$r['type'],$r,$meta[$r['id']]??[]);}
            foreach($read('users',['id'=>'ID','email'=>'user_email'],'',' ORDER BY ID') as $u)$users[]=['id'=>(string)$u['id'],'email'=>(string)$u['email']];
        }else{
            // Keep original category IDs, and expose real names and parent paths as typed mapping inputs.
            $categories=[];
            foreach($read('category',['id'=>'id','name'=>'name','parent'=>'parentid','slug'=>'alt_name'],'',' ORDER BY id') as $c){
                if(count($categories)>=self::LIMIT)throw new \RuntimeException('DLE category inventory exceeds 500.');
                $categories[(string)$c['id']]=['id'=>(string)$c['id'],'name'=>(string)$c['name'],'parent'=>(string)$c['parent'],'slug'=>(string)$c['slug']];
            }
            foreach(['post'=>'post','static'=>'page'] as $s=>$type){
                $staticCols=$schema[$prefix.$s]??[];
                $map=['id'=>'id','title'=>$s==='static'&&!in_array('title',$staticCols,true)?'descr':'title','body'=>$s==='post'?'full_story':'template','excerpt'=>'short_story','slug'=>$s==='post'?'alt_name':'name','date'=>'date','author'=>'autor','status'=>'approve','categories'=>'category','tags'=>'tags','xfields'=>'xfields','access'=>'access','groups'=>'grouplevel','views'=>'news_read','rating'=>'rating','seo_title'=>'metatitle','seo_description'=>$s==='static'?'metadescr':'descr','seo_keywords'=>$s==='static'?'metakeys':'keywords'];
                foreach($read($s,$map,'',' ORDER BY id') as $r){
                    $meta=[];foreach(explode('||',(string)$r['xfields']) as $pair){$parts=explode('|',$pair,2);if(count($parts)===2&&preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$parts[0]))$meta['meta:'.$parts[0]]=str_replace('&#124;','|',$parts[1]);}
                    $paths=[];$names=[];foreach(array_filter(array_map('trim',explode(',',(string)$r['categories'])),fn($v)=>$v!==''&&$v!=='0') as $cid){
                        if(!isset($categories[$cid])){$warnings['missing_category']='Some DLE category IDs are missing from the category table.';continue;}
                        $names[]=$categories[$cid]['name'];$path=[];$seen=[];$cursor=$cid;
                        while(isset($categories[$cursor])){if(isset($seen[$cursor])||count($path)>=64)throw new \RuntimeException('DLE category hierarchy contains a cycle or exceeds 64 levels.');$seen[$cursor]=true;$c=$categories[$cursor];array_unshift($path,$c);$cursor=$c['parent'];}
                        if($cursor!==''&&$cursor!=='0')$warnings['missing_parent']='Some DLE category parents are missing.';
                        $paths[]=$path;
                    }
                    $meta['meta:category_names']=implode(', ',$names);$meta['meta:category_tree']=$paths;
                    foreach(['views','rating','seo_title','seo_description','seo_keywords'] as $k)if($r[$k]!=='')$meta['meta:dle_'.$k]=(string)$r[$k];
                    $publicAccess=in_array(trim((string)$r['access']),['','0'],true)&&in_array(strtolower(trim((string)$r['groups'])),['','0','all'],true);
                    $staticKnown=in_array('grouplevel',$schema[$prefix.$s]??[],true)||in_array('approve',$schema[$prefix.$s]??[],true);
                    $approved=$s==='post'?(string)$r['status']==='1':($staticKnown&&in_array((string)$r['status'],['','1'],true));
                    $sourceDate=DleImporter::date((string)$r['date']);if($sourceDate!==null)$r['date']=$sourceDate;
                    if($sourceDate!==null&&$sourceDate>gmdate('Y-m-d H:i:s')){$approved=false;$warnings['future']='Future-dated DLE records remain draft.';}
                    $r['status']=$approved&&$publicAccess?'publish':'draft';
                    if(!$publicAccess)$warnings['access']='Restricted DLE records are mapped to draft; source ACLs are not imported.';
                    $record=self::record($type,$r,$meta);$record['origin']='dle';$records[]=$record;
                }
            }
            $warnings['dle_content']='DLE richtext is sanitized; supported simple BBCode is converted. Unknown markup, media and source ACLs are not executed or imported.';
            foreach($read('users',['id'=>'name','email'=>'email'],'',' ORDER BY name') as $u)$users[]=['id'=>(string)$u['id'],'email'=>(string)$u['email']];
        }
        return self::inventory($records,$users,array_values($warnings));
    }
    public function rest(array $config,#[\SensitiveParameter] array $secret):array
    {
        $headers=['Accept'=>'application/json'];if(($secret['username']??'')!=='')$headers['Authorization']='Basic '.base64_encode($secret['username'].':'.$secret['password']);
        $started=microtime(true);$bytes=0;$warnings=['REST includes only visible posts/pages; custom types, private records and email may be unavailable.'];$records=[];$users=[];
        foreach(['posts'=>'post','pages'=>'page','users'=>'user'] as $endpoint=>$type){
            for($page=1;$page<=11;$page++){
                if(microtime(true)-$started>25)throw new \RuntimeException('REST planning time budget exceeded.');
                $fields=$type==='user'?'id,email':'id,type,status,author,title,content,excerpt,slug,date,categories,tags,meta';
                $url=$config['url'].'/wp-json/wp/v2/'.$endpoint.'?per_page=50&page='.$page.'&orderby=id&order=asc&_fields='.$fields;
                [$status,$body]=$this->http->request($url,'GET',$headers,'',2*1024*1024);
                if($type==='user'&&$page===1&&in_array($status,[401,403],true)){$warnings[]='REST user inventory unavailable; explicit author mapping is required.';break;}
                if($page>1&&$status===400&&in_array(json_decode($body,true)['code']??'',['rest_post_invalid_page_number','rest_user_invalid_page_number'],true))break;
                if($status!==200)throw new \RuntimeException('WordPress planning collection '.$endpoint.' returned HTTP '.$status.'.');
                if(($bytes+=strlen($body))>self::MAX_BYTES)throw new \RuntimeException('REST planning inventory exceeds 16 MiB.');
                $rows=json_decode($body,true,512,JSON_THROW_ON_ERROR);if(!is_array($rows)||!array_is_list($rows))throw new \RuntimeException('Invalid REST collection.');
                foreach($rows as $r){if(!is_int($r['id']??null)||$r['id']<1)throw new \RuntimeException('Invalid REST record ID.');if($type==='user'){$users[]=['id'=>(string)$r['id'],'email'=>(string)($r['email']??'')];continue;}$meta=[];foreach(($r['meta']??[]) as $k=>$v)if(is_scalar($v)||is_array($v))$meta['meta:'.$k]=$v;$records[]=self::record($type,['id'=>$r['id'],'status'=>$r['status']??'draft','author'=>$r['author']??'','title'=>$r['title']['rendered']??'','body'=>$r['content']['rendered']??'','excerpt'=>$r['excerpt']['rendered']??'','slug'=>$r['slug']??'','date'=>$r['date']??'','categories'=>implode(',', $r['categories']??[]),'tags'=>implode(',', $r['tags']??[])],$meta);}
                if(count($records)>self::LIMIT||count($users)>self::LIMIT)throw new \RuntimeException('REST source exceeds planning limit (500).');if(count($rows)<50)break;
            }
        }
        return self::inventory($records,$users,$warnings);
    }
    public function wxr(#[\SensitiveParameter] string $xml):array
    {
        (new WxrDiscovery())->scan($xml);$r=new \XMLReader();$r->XML($xml,null,LIBXML_NONET|LIBXML_COMPACT);$r->setParserProperty(\XMLReader::LOADDTD,false);$r->setParserProperty(\XMLReader::SUBST_ENTITIES,false);$records=[];$users=[];$item=null;$meta=[];$author=null;$pair=null;
        try{while($r->read()){$n=$r->localName;$d=$r->depth;$wp=preg_match('~^https?://wordpress\.org/export/[0-9.]+/$~D',$r->namespaceURI)===1;
            if($r->nodeType===\XMLReader::ELEMENT){
                if($d===2&&$n==='item'&&$r->namespaceURI===''){$item=[];$meta=[];continue;}
                if($d===2&&$wp&&$n==='author'){$author=[];continue;}
                if($author!==null&&$d===3&&$wp&&in_array($n,['author_login','author_email'],true)){$author[$n]=$r->readString();continue;}
                if($item===null)continue;
                if($d===3){$key=match(true){$wp&&$n==='post_id'=>'id',$wp&&$n==='post_type'=>'type',$wp&&$n==='status'=>'status',$wp&&$n==='post_name'=>'slug',$wp&&$n==='post_date'=>'date',$n==='title'&&$r->namespaceURI==='' =>'title',$n==='creator'&&$r->namespaceURI==='http://purl.org/dc/elements/1.1/'=>'author',$n==='encoded'&&$r->namespaceURI==='http://purl.org/rss/1.0/modules/content/'=>'body',$n==='encoded'&&str_contains($r->namespaceURI,'wordpress.org/export/')&&str_ends_with($r->namespaceURI,'/excerpt/')=>'excerpt',default=>null};if($key!==null)$item[$key]=$r->readString();elseif($wp&&$n==='postmeta')$pair=[];elseif($n==='category'&&$r->namespaceURI===''){$domain=$r->getAttribute('domain');$slug=$r->getAttribute('nicename')??$r->readString();$k=$domain==='category'?'categories':($domain==='post_tag'?'tags':null);if($k!==null)$item[$k]=isset($item[$k])?$item[$k].','.$slug:$slug;}}
                if($d===4&&$wp&&$pair!==null&&in_array($n,['meta_key','meta_value'],true))$pair[$n]=$r->readString();
            }elseif($r->nodeType===\XMLReader::END_ELEMENT){if($d===2&&$n==='author'&&$author!==null){$users[]=['id'=>$author['author_login']??'','email'=>$author['author_email']??''];$author=null;}if($d===3&&$n==='postmeta'&&$pair!==null){if(isset($pair['meta_key']))$meta['meta:'.$pair['meta_key']]??=$pair['meta_value']??'';$pair=null;}if($d===2&&$n==='item'&&$item!==null){$type=$item['type']??'';if(!in_array($type,['attachment','revision','nav_menu_item','customize_changeset','wp_global_styles','wp_template','wp_template_part','wp_navigation','oembed_cache'],true))$records[]=self::record($type,$item,$meta);$item=null;if(count($records)>self::LIMIT||count($users)>self::LIMIT)throw new \RuntimeException('WXR exceeds planning limit (500).');}}
        }}finally{$r->close();}return self::inventory($records,$users,['WXR includes exported content and authors only. Taxonomy, media and relationships are deferred.']);
    }
}
