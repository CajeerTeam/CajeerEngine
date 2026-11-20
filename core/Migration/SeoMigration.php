<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Content\{ContentEntryRepository,RevisionConflict};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\{PublicPath,RouteIndex};
use PDO;

/** Applies verified legacy URLs and SEO data to a completed content import. No source code is executed. */
final readonly class SeoMigration
{
    public function __construct(private string $root,private DatabaseManager $db,private ContentEntryRepository $entries) {}

    public function report(string $project):?array
    {
        if(!$this->db->tableExists('ce_migration_seo_runs'))return null;
        $q=$this->db->connection()->prepare('SELECT result FROM ce_migration_seo_runs WHERE project_id=:project');
        $q->execute(['project'=>$project]);$result=$q->fetchColumn();
        return is_string($result)?json_decode($result,true,64,JSON_THROW_ON_ERROR):null;
    }

    /** A map is an explicit set of verified old URLs keyed by source_type:source_id. */
    public static function parseMap(string $file):array
    {
        if($file==='')return [];
        if(is_link($file)||!is_file($file)||($size=filesize($file))===false||$size>1048576)throw new \InvalidArgumentException('Legacy URL map must be a regular JSON file under 1 MiB.');
        $bytes=file_get_contents($file);if($bytes===false)throw new \RuntimeException('Cannot read legacy URL map.');
        $map=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($map)||(array_is_list($map)&&$map!==[])||count($map)>1000)throw new \InvalidArgumentException('Legacy URL map must be an object with at most 1000 entries.');
        foreach($map as $key=>$paths){
            if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{1,100}:[A-Za-z0-9_-]{1,150}$/D',$key))throw new \InvalidArgumentException('Invalid legacy map source key.');
            if(is_string($paths))$paths=[$paths];
            if(!is_array($paths)||!array_is_list($paths)||count($paths)>12)throw new \InvalidArgumentException('Legacy URLs must be strings or lists of up to 12 URLs.');
            foreach($paths as $p)if(!is_string($p)||strlen($p)>2048||$p==='')throw new \InvalidArgumentException('Invalid legacy URL.');
            $map[$key]=$paths;
        }
        ksort($map,SORT_STRING);return $map;
    }

    /** User-supplied permalink templates are never guessed from a slug. */
    public static function pattern(string $pattern):string
    {
        if($pattern==='')return '';
        if(strlen($pattern)>250||!str_starts_with($pattern,'/')||str_starts_with($pattern,'//')||preg_match('/[\\\\\x00-\x20\x7f?#]/',$pattern))throw new \InvalidArgumentException('Permalink template must be a local path.');
        $check=preg_replace('/%(?:id|post_id|slug|postname|year|monthnum|day)%/','x',$pattern);
        if($check===null||str_contains($check,'%')||!str_contains($pattern,'%slug%')&&!str_contains($pattern,'%postname%')&&!str_contains($pattern,'%id%')&&!str_contains($pattern,'%post_id%'))throw new \InvalidArgumentException('Unsupported permalink template.');
        PublicPath::content($check);return $pattern;
    }

    public static function legacy(string $raw,string $origin):array
    {
        if(strlen($raw)>2048||preg_match('/[\\\\\x00-\x20\x7f#]/',$raw)||str_starts_with($raw,'//'))throw new \InvalidArgumentException('Unsafe legacy URL.');
        if(!str_starts_with($raw,'/')){
            $parts=parse_url($raw);$base=parse_url($origin);
            if(!is_array($parts)||!is_array($base)||strtolower($parts['scheme']??'')!==strtolower($base['scheme']??'')||strtolower($parts['host']??'')!==strtolower($base['host']??'')||isset($parts['port'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new \InvalidArgumentException('Legacy URL must belong to the configured source site.');
            $raw=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
        }
        $parts=parse_url($raw);if(!is_array($parts))throw new \InvalidArgumentException('Invalid legacy URL.');
        $path=PublicPath::content($parts['path']??'/');$query=$parts['query']??'';
        if($query!==''){
            if($path!=='/'||!preg_match('/^(p|page_id)=([1-9][0-9]{0,15})$/D',$query,$m))throw new \InvalidArgumentException('Only verified WordPress plain permalinks ?p=ID and ?page_id=ID are supported.');
            return ['kind'=>'query','key'=>$m[1].':'.$m[2],'url'=>'/?'.$query];
        }
        if($path==='/')throw new \InvalidArgumentException('The root path may not be redirected.');
        return ['kind'=>'path','key'=>$path,'url'=>$path];
    }

    public static function expand(string $pattern,array $source):string
    {
        $fields=$source['fields']??[];
        $slug=trim((string)($fields['slug']??''));
        if($slug==='')throw new \InvalidArgumentException('Source record has no permalink slug. Use an explicit legacy URL map.');
        // A slug may be Unicode, but must remain one path segment.
        if(str_contains($slug,'/')||str_contains($slug,'\\')||str_contains($slug,'?')||str_contains($slug,'#'))throw new \InvalidArgumentException('Unsafe source permalink slug.');
        $date=WordPressContent::date((string)($fields['meta:wp_date_gmt']??''))??ContentMigrationImporter::date((string)($fields['date']??''));
        $replace=['%id%'=>rawurlencode((string)$source['id']),'%post_id%'=>rawurlencode((string)$source['id']),'%slug%'=>rawurlencode($slug),'%postname%'=>rawurlencode($slug),'%year%'=>$date?substr($date,0,4):'', '%monthnum%'=>$date?substr($date,5,2):'', '%day%'=>$date?substr($date,8,2):''];
        $result=strtr($pattern,$replace);
        if(str_contains($result,'//')||preg_match('~/(?:/|$)~',$result)&&$result!=='/'&&str_ends_with($result,'//'))throw new \InvalidArgumentException('Permalink date is missing or malformed.');
        return PublicPath::content($result);
    }

    /** Source metadata uses known plugin keys. We never eval template functions or serialized PHP. */
    public static function metadata(array $source,string $targetPath,string $base):array
    {
        $f=$source['fields']??[];$wp=($source['origin']??'')==='wordpress';
        $first=static function(array $keys)use($f):string{foreach($keys as $key){$v=$f[$key]??null;if(is_string($v)&&trim($v)!=='')return trim($v);}return '';};
        $title=$wp?$first(['meta:_yoast_wpseo_title','meta:rank_math_title','meta:_aioseo_title']):$first(['meta:dle_seo_title']);
        $description=$wp?$first(['meta:_yoast_wpseo_metadesc','meta:rank_math_description','meta:_aioseo_description']):$first(['meta:dle_seo_description']);
        foreach(['title'=>&$title,'description'=>&$description] as &$v){$v=preg_replace('/%%title%%/u',(string)($f['title']??''),$v)??'';if(preg_match('/%%[^%]+%%|%[a-z_]+%/iu',$v))$v='';$v=trim(strip_tags($v));}unset($v);
        if(strlen($title)>300)$title='';if(strlen($description)>2000)$description='';
        $noindex=$wp&&in_array((string)($f['meta:_yoast_wpseo_meta-robots-noindex']??''),['1','true'],true);
        // Rank Math stores robots as a PHP-serialized list in wp_postmeta, while
        // REST may expose a native list. Never unserialize untrusted plugin data.
        if($wp&&self::rankMathNoindex($f['meta:rank_math_robots']??null))$noindex=true;
        return ['title'=>$title,'description'=>$description,'canonical'=>$targetPath!==''?$base.$targetPath:'','noindex'=>$noindex];
    }

    private static function rankMathNoindex(mixed $robots):bool
    {
        if(is_array($robots))return in_array('noindex',$robots,true);
        if(!is_string($robots)||$robots===''||strlen($robots)>4096)return false;
        if($robots==='noindex')return true;
        // A conservative token check on serialized arrays. We do not instantiate PHP objects
        // or evaluate any arbitrary source metadata; malformed data cannot execute code.
        return preg_match('/(?:^|[;{])s:7:"noindex";/', $robots)===1;
    }

    private static function hashData(array $data):string{return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}

    /** Entire stage commits atomically with public route/index updates and immutable execution receipt. */
    public function execute(array $project,array $inventory,string $origin,array $aliases,string $pattern=''):array
    {
        if(($project['status']??'')!=='completed'||empty($project['execution']['records']))throw new \RuntimeException('Complete content migration before SEO migration.');
        if($this->entries->storage()!=='database'||!$this->db->tableExists('ce_migration_seo_runs')||!$this->db->tableExists('ce_migration_seo_path_evidence'))throw new \RuntimeException('SEO migration requires SQL migrations through 0025.');
        $origin=MediaMigration::validateOrigin($origin);$pattern=self::pattern($pattern);
        if($origin===''&&$aliases!==[])throw new \InvalidArgumentException('Set --origin for verified legacy URL mapping.');
        $id=$project['id'];$source=$project['source'];$content=$project['execution'];
        $table=$source==='dle-db'?'ce_dle_migration_runs':'ce_wordpress_migration_runs';
        if(!in_array($source,['dle-db','wordpress-db','wordpress-rest','wordpress-wxr'],true))throw new \InvalidArgumentException('Unsupported migration source.');
        $q=$this->db->connection()->prepare('SELECT plan_sha256,result FROM '.$table.' WHERE project_id=:id');$q->execute(['id'=>$id]);
        $confirmed=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($confirmed)||!hash_equals((string)$confirmed['plan_sha256'],(string)($content['plan_sha256']??''))||json_decode((string)$confirmed['result'],true,128,JSON_THROW_ON_ERROR)!==$content)throw new RevisionConflict('Content migration receipt is missing or differs from the persisted execution.');

        $bySource=[];foreach(($inventory['records']??[]) as $r)$bySource[$r['type'].':'.$r['id']]=$r;
        $imported=[];foreach($content['records'] as $r)$imported[$r['source_type'].':'.$r['source_id']]=$r;
        foreach($aliases as $key=>$urls)if(!isset($imported[$key]))throw new \InvalidArgumentException('URL map contains a record not in the completed import: '.$key);
        $base=PublicPath::base((new \CajeerEngine\Runtime\ConfigRepository($this->root))->string('app.url'));
        $entries=[];$redirects=[];$query=[];$rewriteMap=[];$skipped=[];
        $routes=new RouteIndex($this->root,$this->db);$redirectRepo=new RedirectRepository($this->root,$this->db);
        foreach($content['records'] as $rec){
            if(!in_array($rec['action']??'', ['create','update'],true))continue;
            $key=$rec['source_type'].':'.$rec['source_id'];$sourceRecord=$bySource[$key]??null;
            if(!$sourceRecord)throw new RevisionConflict('Source content changed after import: '.$key);
            $entry=$this->entries->findById((string)$rec['target_type'],(string)$rec['target_id'])??throw new RevisionConflict('Target entry disappeared: '.$key);
            $route=$routes->forEntry((string)$entry['id']);$path=$route['path']??'';
            $entries[$key]=['import'=>$rec,'entry'=>$entry,'source'=>$sourceRecord,'path'=>$path,'seo'=>self::metadata($sourceRecord,$path,$base)];
            $urls=$aliases[$key]??[];
            if($urls===[]&&$pattern!==''&&$path!=='')$urls=[self::expand($pattern,$sourceRecord)];
            if($urls!==[]&&$path===''){$skipped[]=['source'=>$key,'reason'=>'unpublished_no_public_route'];continue;}
            foreach($urls as $raw){
                $url=self::legacy($raw,$origin);$old=$url['key'];if($url['kind']==='path'&&$old===$path)continue;
                $group=$url['kind']==='query'?'query':'path';
                if(isset($redirects[$old])&&$group==='path'&&$redirects[$old]!==$path||isset($query[$old])&&$group==='query'&&$query[$old]!==$path)throw new RevisionConflict('Two imported records claim one legacy URL: '.$url['url']);
                if($group==='query'){$query[$old]=$path;continue;}
                $redirects[$old]=$path;
                $rewriteMap[$old]=$path;
                if($origin!==''){$rewriteMap[$origin.$old]=$path;$rewriteMap['//'.substr($origin,strpos($origin,'://')+3).$old]=$path;}
            }
        }
        uksort($rewriteMap,fn($a,$b)=>strlen($b)<=>strlen($a)?:strcmp($a,$b));
        ksort($redirects,SORT_STRING);ksort($query,SORT_STRING);
        $configHash=hash('sha256',json_encode([$origin,$aliases,$pattern,$content['plan_sha256']??'',array_map(static fn($r)=>[$r['source'],$r['seo'],$r['path']],$entries)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if($prior=$this->report($id)){if(!hash_equals($prior['configuration_sha256'],$configHash))throw new RevisionConflict('SEO migration already completed with different settings.');return $prior;}
        return $routes->mutate(function()use($id,$source,$entries,$redirects,$query,$rewriteMap,$skipped,$configHash,$redirectRepo,$routes){
            if($prior=$this->report($id)){if(!hash_equals($prior['configuration_sha256'],$configHash))throw new RevisionConflict('SEO migration already completed with different settings.');return $prior;}
            foreach($redirects as $old=>$target){
                $existing=$routes->lookup($old);if($existing!==null&&!(($existing['kind']??'')==='alias'&&($existing['target']??'')===$target))throw new RevisionConflict('Legacy URL collides with a public route: '.$old);
                $rule=$redirectRepo->byPath($old);if($rule!==null&&(!(bool)$rule['enabled']||(int)$rule['status_code']!==301||$rule['target_url']!==$target))throw new RevisionConflict('Legacy URL conflicts with an existing redirect: '.$old);
            }
            $pdo=$this->db->connection();foreach($query as $key=>$target){$q=$pdo->prepare('SELECT target_path FROM ce_migration_seo_query_urls WHERE query_key=:key');$q->execute(['key'=>$key]);$previous=$q->fetchColumn();if($previous!==false&&$previous!==$target)throw new RevisionConflict('Legacy query URL conflicts with another migration.');}
            // Editor changes are detected before ANY update or redirect write.
            foreach($entries as $r){$rec=$r['import'];$entry=$this->entries->findById((string)$rec['target_type'],(string)$rec['target_id']);if(!$entry)throw new RevisionConflict('Target entry disappeared.');
                if($rewriteMap!==[]){
                    $q=$pdo->prepare('SELECT updated_sha,status FROM ce_migration_media_rewrites WHERE project_id=:project AND target_id=:id');$q->execute(['project'=>$id,'id'=>$entry['id']]);$m=$q->fetch(PDO::FETCH_ASSOC);
                    $clean=$m&&$m['status']==='done'&&hash_equals($m['updated_sha'],self::hashData($entry['data']));
                    if(!$clean&&(int)$entry['revision']!==(int)$rec['revision'])throw new RevisionConflict('Target entry was edited since content/media migration: '.$entry['id']);
                }
            }
            $rewritten=0;$links=0;$metadata=0;$newRedirects=0;$newQuery=0;
            foreach($entries as $r){$entry=$this->entries->findById((string)$r['import']['target_type'],(string)$r['import']['target_id']);$seo=$r['seo'];
                $q=$pdo->prepare('INSERT INTO ce_migration_seo_meta(project_id,target_id,title,description,canonical_url,noindex,created_at) VALUES(:project,:target,:title,:description,:canonical,:noindex,:created)');
                $q->execute(['project'=>$id,'target'=>$entry['id'],'title'=>$seo['title'],'description'=>$seo['description'],'canonical'=>$seo['canonical'],'noindex'=>$seo['noindex']?1:0,'created'=>gmdate(DATE_ATOM)]);$metadata++;
                $count=0;$new=MediaMigration::rewrite($entry['data'],$rewriteMap,$count);
                if($count>0){$this->entries->update((string)$r['import']['target_type'],$entry['id'],['title'=>$entry['title'],'slug'=>$entry['slug'],'status'=>$entry['status'],'locale'=>$entry['locale'],'data'=>$new,'expected_revision'=>(int)$entry['revision']], 'migration_seo');$rewritten++;$links+=$count;}
            }
            foreach($redirects as $old=>$target){if($redirectRepo->byPath($old)===null){$redirectRepo->save(['source_path'=>$old,'target_url'=>$target,'status_code'=>301,'enabled'=>true]);$newRedirects++;}}
            $evidence=$pdo->prepare('INSERT INTO ce_migration_seo_path_evidence(project_id,source_path,target_path) VALUES(:project,:source,:target)');
            foreach($redirects as $old=>$target)$evidence->execute(['project'=>$id,'source'=>$old,'target'=>$target]);
            foreach($query as $key=>$target){$q=$pdo->prepare('INSERT INTO ce_migration_seo_query_urls(project_id,query_key,target_path,created_at) VALUES(:project,:key,:target,:created)');$q->execute(['project'=>$id,'key'=>$key,'target'=>$target,'created'=>gmdate(DATE_ATOM)]);$newQuery++;}
            $result=['project_id'=>$id,'source'=>$source,'configuration_sha256'=>$configHash,'completed_at'=>gmdate(DATE_ATOM),'metadata_entries'=>$metadata,'new_redirects'=>$newRedirects,'new_query_redirects'=>$newQuery,'rewritten_entries'=>$rewritten,'rewritten_links'=>$links,'skipped'=>$skipped,'sitemap_url'=>'/sitemap.xml'];
            $q=$pdo->prepare('INSERT INTO ce_migration_seo_runs(project_id,configuration_sha256,completed_at,result) VALUES(:project,:sha,:completed,:result)');$q->execute(['project'=>$id,'sha'=>$configHash,'completed'=>$result['completed_at'],'result'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            return $result;
        });
    }
}
