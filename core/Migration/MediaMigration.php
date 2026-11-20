<?php

declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Content\{ContentEntryRepository, ContentEntryValidator, ContentTypeRepository, RevisionConflict};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Media\MediaRepository;
use PDO;

/** Operator-only, resumable media transfer following a committed DLE/WordPress content import.
 *  Each file is committed independently; entry changes use optimistic revision checks and
 *  an intent journal to make crashes between CMS update and receipt recoverable.
 */
final readonly class MediaMigration
{
    private const MAX_FILES=20000;
    private const MAX_TOTAL=4294967296;
    private const EXTENSIONS=['jpg','jpeg','png','gif','webp','pdf','txt','md','zip'];

    public function __construct(private DatabaseManager $db,private ContentEntryRepository $entries,private MediaRepository $media,private ?ContentTypeRepository $types=null){}

    public static function validateOrigin(string $origin):string
    {
        if($origin==='')return '';
        $p=parse_url($origin);
        if(!is_array($p)||!in_array(strtolower((string)($p['scheme']??'')),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||isset($p['port'])||!filter_var($origin,FILTER_VALIDATE_URL)||preg_match('/[\\\\\x00-\x20\x7f]/',$origin)||strlen($origin)>500)throw new \InvalidArgumentException('Origin must be a plain HTTP(S) site origin without credentials, port, path or query.');
        if(!in_array($p['path']??'',['','/'],true))throw new \InvalidArgumentException('Origin must not have a path.');
        return strtolower($p['scheme']).'://'.strtolower($p['host']);
    }

    /** A symlink or an unexpected path is never traversed, even if nested in the mirror. */
    public static function discover(string $root):array
    {
        $base=realpath($root);
        if($base===false||!is_dir($base)||is_link($root))throw new \InvalidArgumentException('Source media directory is missing or is a symlink.');
        $files=[];$unsupported=[];$total=0;$seen=0;
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $item){
            if($item->isLink())throw new \RuntimeException('Source media mirror contains symlinks.');
            if(!$item->isFile())continue;
            if(++$seen>self::MAX_FILES)throw new \RuntimeException('Source media mirror exceeds 20000 files.');
            $full=$item->getPathname();$rel=substr($full,strlen($base)+1);
            $rel=str_replace(DIRECTORY_SEPARATOR,'/',$rel);
            if(!self::safeRelative($rel))throw new \RuntimeException('Unsafe source media path.');
            $bytes=$item->getSize();if($bytes===false||($total+=$bytes)>self::MAX_TOTAL)throw new \RuntimeException('Source media mirror exceeds 4 GiB.');
            if(in_array(strtolower(pathinfo($rel,PATHINFO_EXTENSION)),self::EXTENSIONS,true))$files[$rel]=$full;
            else $unsupported[]=$rel;
        }
        ksort($files,SORT_STRING);sort($unsupported,SORT_STRING);
        return ['files'=>$files,'unsupported'=>$unsupported,'total_files'=>$seen,'total_bytes'=>$total];
    }
    public static function safeRelative(string $path):bool
    {
        if($path===''||strlen($path)>500||str_starts_with($path,'/')||str_contains($path,'\\')||str_contains($path,"\0")||preg_match('/[\x00-\x1f\x7f]/',$path))return false;
        foreach(explode('/',$path) as $segment)if($segment===''||$segment==='.'||$segment==='..')return false;
        return true;
    }
    /** Only exact legacy upload URLs are replaced; external sites and filename prefixes are untouched. */
    public static function replacementMap(array $items,string $origin='',bool $wordpress=false):array
    {
        $origin=self::validateOrigin($origin);$map=[];
        foreach($items as $relative=>$url){
            if(!self::safeRelative($relative)||!is_string($url)||!str_starts_with($url,'/'))throw new \InvalidArgumentException('Invalid media mapping.');
            $encoded=implode('/',array_map('rawurlencode',explode('/',$relative)));
            foreach(array_unique([$relative,$encoded]) as $part){
                foreach($wordpress?['/wp-content/uploads/','/uploads/']:['/uploads/'] as $prefix){
                    $legacy=$prefix.$part;$map[$legacy]=$url;
                    if($origin!==''){$map[$origin.$legacy]=$url;$map['//'.substr($origin,strpos($origin,'://')+3).$legacy]=$url;}
                }
            }
        }
        // Longer URLs first. Never allow a short relative path to replace a suffix of an unrelated absolute URL.
        uksort($map,fn($a,$b)=>strlen($b)<=>strlen($a)?:strcmp($a,$b));return $map;
    }
    public static function rewrite(mixed $value,array $map,int &$count=0):mixed
    {
        if(is_array($value)){foreach($value as $key=>$v)$value[$key]=self::rewrite($v,$map,$count);return $value;}
        if(!is_string($value)||$value==='')return $value;
        // Temporary markers prevent a new /uploads/... URL from being matched again
        // when the source mirror happens to contain the same relative filename.
        if(str_contains($value,"\x01")||str_contains($value,"\x02"))throw new \RuntimeException('Unexpected control bytes in migrated content.');
        $markers=[];
        foreach($map as $old=>$new){
            $left=str_starts_with($old,'/')&&!str_starts_with($old,'//')?'(?<![a-zA-Z0-9:/.])':'(?<![a-zA-Z0-9/.])';
            $pattern='~'.$left.preg_quote($old,'~').'(?=$|[\\s\\"\'<>?#)\\],;])~u';
            $next=preg_replace_callback($pattern,static function(array $matches)use($new,&$count,&$markers){$marker="\x01".count($markers)."\x02";$markers[$marker]=$new;$count++;return $marker;},$value);
            if($next===null)throw new \RuntimeException('Invalid UTF-8 in migrated content.');
            $value=$next;
        }
        return strtr($value,$markers);
    }
    /** Report unresolved references; never touch links pointing at unrelated remote domains. */
    public static function missingReferences(mixed $data,array $importedUrls,string $origin=''):array
    {
        if(is_array($data)){
            $all=[];foreach($data as $value)foreach(self::missingReferences($value,$importedUrls,$origin) as $item)$all[$item]=true;
            return array_keys($all);
        }
        if(!is_string($data)||!str_contains($data,'uploads/'))return [];
        $origin=self::validateOrigin($origin);$known=array_fill_keys(array_values($importedUrls),true);$missing=[];
        preg_match_all('~(?:https?://[A-Za-z0-9.-]+)?/(?:wp-content/uploads|uploads)/[^\s"\x27<>?#)\],;]+~u',$data,$matches);
        foreach($matches[0] as $url){
            if(isset($known[$url])||preg_match('~^/uploads/[0-9]{4}/[0-9]{2}/[a-f0-9-]{36}\.[a-z0-9]+$~D',$url))continue;
            if(str_starts_with($url,'http')&&($origin===''||!str_starts_with($url,$origin.'/')))continue;
            $missing[$url]=true;
        }
        return array_keys($missing);
    }
    /** Collect legacy relative asset paths referenced by the destination content. */
    public static function referencedPaths(mixed $data,string $origin=''):array
    {
        if(is_array($data)){$paths=[];foreach($data as $value)foreach(self::referencedPaths($value,$origin) as $path)$paths[$path]=true;return array_keys($paths);}
        if(!is_string($data)||!str_contains($data,'uploads/'))return [];
        $origin=self::validateOrigin($origin);$paths=[];
        preg_match_all('~(?:https?://[A-Za-z0-9.-]+)?/(?:wp-content/uploads|uploads)/[^\s"\x27<>?#)\],;]+~u',$data,$matches);
        foreach($matches[0] as $url){
            if(str_starts_with($url,'http')&&($origin===''||!str_starts_with($url,$origin.'/')))continue;
            $relative=WordPressMediaMetadata::relative($url);
            if($relative!==null)$paths[$relative]=true;
        }
        return array_keys($paths);
    }
    private function receipt(array $project):array
    {
        $table=$project['source']==='dle-db'?'ce_dle_migration_runs':'ce_wordpress_migration_runs';
        $q=$this->db->connection()->prepare('SELECT plan_sha256,result FROM '.$table.' WHERE project_id=:id');$q->execute(['id'=>$project['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r||!hash_equals((string)$r['plan_sha256'],(string)($project['plan']['sha256']??'')))throw new \RuntimeException('First execute the currently approved content migration.');
        return json_decode($r['result'],true,128,JSON_THROW_ON_ERROR);
    }
    public function report(string $project):array
    {
        if(!$this->db->tableExists('ce_migration_media_files'))return ['status'=>'not_installed'];
        $q=$this->db->connection()->prepare('SELECT COUNT(*),COALESCE(SUM(size_bytes),0) FROM ce_migration_media_files WHERE project_id=:id');$q->execute(['id'=>$project]);[$files,$bytes]=$q->fetch(PDO::FETCH_NUM);
        $q=$this->db->connection()->prepare('SELECT COUNT(*),COALESCE(SUM(replacements),0) FROM ce_migration_media_rewrites WHERE project_id=:id AND status=:status');$q->execute(['id'=>$project,'status'=>'done']);[$entries,$links]=$q->fetch(PDO::FETCH_NUM);
        return ['status'=>((int)$files||(int)$entries)?'partially_or_fully_imported':'not_started','imported_files'=>(int)$files,'imported_bytes'=>(int)$bytes,'rewritten_entries'=>(int)$entries,'rewritten_links'=>(int)$links];
    }
    public function execute(array $project,string $uploads,string $origin='',array $wpMetadata=[],?string $featuredField=null):array
    {
        $source=$project['source']??'';
        if(!in_array($source,['dle-db','wordpress-db','wordpress-rest','wordpress-wxr'],true))throw new \InvalidArgumentException('Unsupported migration source.');
        if(!preg_match('/^[a-f0-9]{32}$/D',(string)($project['id']??'')))throw new \InvalidArgumentException('Invalid migration project.');
        if($this->entries->storage()!=='database')throw new \RuntimeException('Media migration requires database-backed content.');
        if(!$this->db->tableExists('ce_migration_media_files')||!$this->db->tableExists('ce_migration_media_rewrites'))throw new \RuntimeException('Apply migration 0021 before importing media.');
        $receipt=$this->receipt($project);$origin=self::validateOrigin($origin);
        if($featuredField!==null&&(!preg_match('/^[a-z][a-z0-9_]{1,99}$/D',$featuredField)||!str_starts_with($source,'wordpress-')||$this->types===null))throw new \InvalidArgumentException('Featured field requires a WordPress migration and a valid target media field.');
        // Preflight all target revisions before copying potentially sensitive source media.
        $seenTargets=[];
        foreach($receipt['records']??[] as $imported){
            if(!in_array($imported['action']??'', ['create','update'],true))continue;
            $target=(string)($imported['target_id']??'');
            if(isset($seenTargets[$target]))throw new RevisionConflict('Content import receipt contains duplicate target IDs.');
            $seenTargets[$target]=true;
            $entry=$this->entries->findById((string)$imported['target_type'],$target)??throw new RevisionConflict('Imported target entry is missing.');
            $q=$this->db->connection()->prepare('SELECT status,original_revision,original_sha,updated_sha FROM ce_migration_media_rewrites WHERE project_id=:project AND target_id=:id');
            $q->execute(['project'=>$project['id'],'id'=>$target]);$intent=$q->fetch(PDO::FETCH_ASSOC);
            if(!$intent&&((int)$entry['revision']!==(int)($imported['revision']??0)))throw new RevisionConflict('Imported entry was edited; media import cannot overwrite it: '.$target);
            if($intent&&$intent['status']==='pending'&&!hash_equals($intent['original_sha'],self::dataHash($entry['data']))&&!hash_equals($intent['updated_sha'],self::dataHash($entry['data'])))throw new RevisionConflict('Pending media rewrite has conflicting content: '.$target);
        }
        $sourceRoot=realpath($uploads);$scan=self::discover($uploads);
        $prefix=str_starts_with($source,'wordpress-');$mediaUrls=[];$mediaIds=[];$skipped=[];$fileCount=0;$deduped=0;$publicRefs=[];$publicCount=0;$privateCount=0;
        // Unreferenced files and files belonging exclusively to drafts stay private.
        foreach($receipt['records']??[] as $imported){
            if(!in_array($imported['action']??'', ['create','update'],true))continue;
            $entry=$this->entries->findById((string)$imported['target_type'],(string)$imported['target_id']);
            if($entry===null)throw new RevisionConflict('Imported entry disappeared.');
            if($entry['status']!=='published')continue;
            foreach(self::referencedPaths($entry['data'],$origin) as $path)$publicRefs[$path]=true;
            if($featuredField!==null&&isset($wpMetadata['featured'][(string)$imported['source_id']])){
                $assetId=(string)$wpMetadata['featured'][(string)$imported['source_id']];
                $path=WordPressMediaMetadata::relative((string)($wpMetadata['attachments'][$assetId]??''));
                if($path!==null)$publicRefs[$path]=true;
            }
        }
        foreach($scan['files'] as $rel=>$filename){
            // Check symlinks and mutations at use time, not just during discovery.
            $real=realpath($filename);
            if($real===false||!str_starts_with($real,$sourceRoot.DIRECTORY_SEPARATOR)||is_link($filename))throw new \RuntimeException('Source media path changed during execution.');
            try{$info=$this->media->inspectImportFile($filename,basename($rel));}
            catch(\InvalidArgumentException $e){$skipped[]=['path'=>$rel,'reason'=>'unsupported_or_invalid_media'];continue;}
            $q=$this->db->connection()->prepare('SELECT media_id,checksum,visibility FROM ce_migration_media_files WHERE project_id=:project AND source_path=:path');$q->execute(['project'=>$project['id'],'path'=>$rel]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if($prior){
                if(!hash_equals($prior['checksum'],$info['checksum']))throw new RevisionConflict('An imported source media file has changed: '.$rel);
                $row=$this->media->find($prior['media_id']);
                if(!$row||($row['visibility']??'public')!==$prior['visibility']||!hash_equals($row['checksum'],$info['checksum'])||!is_file($this->media->sourcePath($row))||!hash_equals($row['checksum'],hash_file('sha256',$this->media->sourcePath($row))))throw new \RuntimeException('Imported media is missing or damaged: '.$rel);
            }else{
                $visibility=isset($publicRefs[$rel])?'public':'private';
                $row=$this->media->createFromPath($filename,basename($rel),['deduplicate'=>true,'visibility'=>$visibility,'title'=>pathinfo($rel,PATHINFO_FILENAME),'folder'=>'Migrated']);
                if(!hash_equals($info['checksum'],$row['checksum']))throw new RevisionConflict('Source file changed during media import: '.$rel);
                if(!empty($row['deduplicated']))$deduped++;
                // If the process stops here, createFromPath's checksum deduplication
                // reuses the same physical media asset on retry.
                $q=$this->db->connection()->prepare('INSERT INTO ce_migration_media_files(project_id,source_path,checksum,media_id,size_bytes,visibility,created_at) VALUES(:project,:path,:hash,:media,:size,:visibility,:date)');
                $q->execute(['project'=>$project['id'],'path'=>$rel,'hash'=>$info['checksum'],'media'=>$row['id'],'size'=>$info['size'],'visibility'=>$visibility,'date'=>gmdate(DATE_ATOM)]);
                $fileCount++;
            }
            $mediaUrls[$rel]=$row['url'];$mediaIds[$rel]=$row['id'];
            if(($row['visibility']??'public')==='public')$publicCount++;else $privateCount++;
        }
        $map=self::replacementMap($mediaUrls,$origin,$prefix);$links=0;$rewritten=0;$unchanged=0;$featuredCount=0;$missingFeatured=[];$unresolved=[];
        $featured=[];
        if($featuredField!==null){
            foreach(($wpMetadata['featured']??[]) as $postId=>$assetId){
                $sourcePath=$wpMetadata['attachments'][(string)$assetId]??'';
                $relative=WordPressMediaMetadata::relative((string)$sourcePath);
                if($relative!==null&&isset($mediaIds[$relative])&&str_starts_with((string)($this->media->find($mediaIds[$relative])['mime_type']??''),'image/'))$featured[(string)$postId]=$mediaIds[$relative];
                else $missingFeatured[]=(string)$postId;
            }
        }
        foreach($receipt['records']??[] as $r){
            if(!in_array($r['action']??'', ['create','update'],true))continue;
            $id=(string)$r['target_id'];$type=(string)$r['target_type'];
            $attach=$featured[(string)($r['source_id']??'')]??null;
            if($attach!==null&&$featuredField!==null){
                $typeInfo=$this->types->find($type);$fields=array_column($typeInfo['fields']??[],null,'handle');
                if(($fields[$featuredField]['type']??null)!=='media'){$missingFeatured[]=(string)($r['source_id']??'');$attach=null;}
            }
            $beforeCount=$links;
            $this->rewriteEntry($project['id'],$type,$id,(int)($r['revision']??0),$map,$rewritten,$links,$unchanged,$featuredField,$attach);
            if($attach!==null&&$links>$beforeCount)$featuredCount++;
            $updated=$this->entries->findById($type,$id);if($updated!==null)foreach(self::missingReferences($updated['data'],$mediaUrls,$origin) as $url){if(count($unresolved)<200)$unresolved[$url]=true;}
        }
        return ['project_id'=>$project['id'],'source'=>$source,'scanned_files'=>$scan['total_files'],'importable_files'=>count($scan['files']),'new_media_files'=>$fileCount,'deduplicated_files'=>$deduped,'public_files'=>$publicCount,'private_files'=>$privateCount,'unsupported_files'=>$scan['unsupported'],'skipped_files'=>$skipped,'rewritten_entries'=>$rewritten,'rewritten_links'=>$links,'unchanged_entries'=>$unchanged,'featured_images_assigned'=>$featuredCount,'unresolved_featured'=>array_values(array_unique($missingFeatured)),'unresolved_references'=>array_keys($unresolved),'totals'=>$this->report($project['id']),'finished_at'=>gmdate(DATE_ATOM)];
    }
    private static function dataHash(array $data):string{return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private function rewriteEntry(string $project,string $type,string $id,int $importRevision,array $map,int &$rewritten,int &$links,int &$unchanged,?string $featuredField=null,?string $featuredId=null):void
    {
        $q=$this->db->connection()->prepare('SELECT status,original_revision,original_sha,updated_sha,replacements FROM ce_migration_media_rewrites WHERE project_id=:project AND target_id=:id');
        $q->execute(['project'=>$project,'id'=>$id]);$intent=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $entry=$this->entries->findById($type,$id)??throw new RevisionConflict('Imported content disappeared before media rewrite.');
        $currentHash=self::dataHash($entry['data']);
        if($intent){
            if($intent['status']==='done'){
                // Editors may modify an already completed entry; never overwrite their work.
                $unchanged++;return;
            }
            if(hash_equals($intent['updated_sha'],$currentHash)){
                $this->completeIntent($project,$id,(int)$intent['replacements']);$rewritten++;$links+=(int)$intent['replacements'];return;
            }
            if(!hash_equals($intent['original_sha'],$currentHash)||(int)$entry['revision']!==(int)$intent['original_revision'])throw new RevisionConflict('Entry changed after media rewrite started: '.$id);
        }elseif((int)$entry['revision']!==$importRevision)throw new RevisionConflict('Imported entry was edited; media will not overwrite changes: '.$id);
        $count=0;$new=self::rewrite($entry['data'],$map,$count);
        if($featuredField!==null&&$featuredId!==null&&empty($new[$featuredField])){$new[$featuredField]=$featuredId;$count++;}
        if($count===0){$unchanged++;return;}
        // Store the exact normalized representation written by the content repository.
        if($this->types!==null){
            $definition=$this->types->find($type)??throw new RevisionConflict('Target content type disappeared.');
            $new=(new ContentEntryValidator($definition))->normalizeForCreate(['title'=>$entry['title'],'slug'=>$entry['slug'],'status'=>$entry['status'],'locale'=>$entry['locale'],'data'=>$new])['data'];
        }
        $before=self::dataHash($entry['data']);$after=self::dataHash($new);
        if($intent){if(!hash_equals($intent['updated_sha'],$after)||$count!==(int)$intent['replacements'])throw new RevisionConflict('Media replacement plan changed after interrupted update.');}
        else{
            $q=$this->db->connection()->prepare('INSERT INTO ce_migration_media_rewrites(project_id,target_id,original_revision,original_sha,updated_sha,replacements,status,updated_at) VALUES(:project,:id,:rev,:before,:after,:replacements,:status,:date)');
            $q->execute(['project'=>$project,'id'=>$id,'rev'=>$entry['revision'],'before'=>$before,'after'=>$after,'replacements'=>$count,'status'=>'pending','date'=>gmdate(DATE_ATOM)]);
        }
        $updated=$this->entries->update($type,$id,['title'=>$entry['title'],'slug'=>$entry['slug'],'status'=>$entry['status'],'locale'=>$entry['locale'],'data'=>$new,'expected_revision'=>(int)$entry['revision']], 'migration_media');
        if(!$updated||!hash_equals(self::dataHash($updated['data']),$after))throw new \RuntimeException('Media rewrite failed verification.');
        $this->completeIntent($project,$id,$count);$rewritten++;$links+=$count;
    }
    private function completeIntent(string $project,string $id,int $count):void
    {
        $q=$this->db->connection()->prepare('UPDATE ce_migration_media_rewrites SET status=:status,updated_at=:date WHERE project_id=:project AND target_id=:id AND status=:pending');
        $q->execute(['status'=>'done','date'=>gmdate(DATE_ATOM),'project'=>$project,'id'=>$id,'pending'=>'pending']);
        if($q->rowCount()!==1)throw new \RuntimeException('Media rewrite receipt did not commit.');
    }
}
