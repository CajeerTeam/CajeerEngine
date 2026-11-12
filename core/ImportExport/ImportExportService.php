<?php

declare(strict_types=1);
namespace CajeerEngine\ImportExport;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Cms\NavigationRepository;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Cms\ThemeConfigRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentEntryValidator;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentTypeValidator;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\SettingsRepository;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Security\SafeHtml;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;

/** Portable site transfer, distinct from full installation backup. Never imports executable code or credentials. */
final class ImportExportService
{
    private const SECTIONS=['content','media','cms','settings'];
    private const SETTINGS=['app.name','app.locale'];
    private ConfigRepository $config;
    private DatabaseManager $db;
    private ContentTypeRepository $types;
    private ContentEntryRepository $entries;
    private MediaRepository $media;
    private StorageManager $storage;
    private NavigationRepository $navigation;
    private RedirectRepository $redirects;
    private ThemeConfigRepository $theme;

    public function __construct(private readonly string $rootPath)
    {
        $this->config=new ConfigRepository($rootPath); $this->db=new DatabaseManager($this->config);
        $this->types=new ContentTypeRepository($rootPath,$this->db); $this->entries=new ContentEntryRepository($rootPath,$this->db);
        $this->storage=new StorageManager($rootPath,$this->config); $this->media=new MediaRepository($rootPath,$this->storage,$this->db);
        $this->navigation=new NavigationRepository($rootPath,$this->db); $this->redirects=new RedirectRepository($rootPath,$this->db); $this->theme=new ThemeConfigRepository($rootPath,$this->db);
    }

    public function exports(): array
    {
        $out=[];
        foreach(glob($this->directory('exports').'/*.{zip,json}',GLOB_BRACE)?:[] as $file) {
            if(is_link($file)||!is_file($file))continue;
            $out[]=['file'=>basename($file),'size'=>filesize($file),'created_at'=>gmdate(DATE_ATOM,filemtime($file)),'format'=>str_ends_with($file,'.zip')?SitePackage::FORMAT:'cajeerengine.export.v2'];
        }
        usort($out,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));return $out;
    }

    public function createExport(array $sections=[], ?string $filename=null): array
    {
        return $this->exclusive(function()use($sections,$filename){
            $this->ready(); $sections=$this->sections($sections); $package=new SitePackage($this->rootPath);
            try {
                $data=[];
                foreach($sections as $section) {
                    if($section==='content') {
                        $content=['types'=>$this->types->all(),'entries'=>[],'revisions'=>[]];
                        foreach($content['types'] as $type) {
                            $handle=$type['handle']; $items=$this->entries->list($handle,['status'=>'all']);
                            foreach($items as &$item) { $content['revisions'][$item['id']]=$this->entries->revisions($handle,$item['id']); unset($item['revisions']); } unset($item);
                            $content['entries'][$handle]=$items;
                        }
                        $data['content']=$content;
                    } elseif($section==='media') {
                        $items=$this->media->all();
                        foreach($items as &$item) {
                            unset($item['s3_replica']);
                            $source=$this->media->sourcePath($item);
                            if(F::hash($source)!==$item['checksum'])throw new \RuntimeException('Missing or changed media: '.$item['id']);
                            $package->addMedia($source,$item['checksum']);
                        } unset($item);
                        $data['media']=['items'=>$items];
                    } elseif($section==='cms') {
                        $data['cms']=['theme'=>$this->theme->get(),'navigation'=>$this->navigation->all(),'redirects'=>$this->redirects->all()];
                    } elseif($section==='settings') $data['settings']=$this->settings();
                }
                $filename=$filename?:'cajeerengine-export-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';
                $this->filename($filename); $path=$this->directory('exports').'/'.$filename;
                if(file_exists($path)||is_link($path))throw new \InvalidArgumentException('Export already exists');
                $manifest=$package->write($path,$data,$this->config->string('app.version','1.2.0'),str_ends_with($filename,'.json'));
                return ['ok'=>true,'path'=>$path,'file'=>$filename,'sha256'=>F::hash($path),'manifest'=>$manifest];
            } finally { $package->close(); }
        });
    }

    public function diff(string $file,array $options=[]): array
    {
        return $this->exclusive(function()use($file,$options){$this->ready();$package=new SitePackage($this->rootPath);
            try { $package->read($this->resolve($file));return $this->publicPlan($this->plan($package,$options),$file); }
            finally { $package->close(); }
        });
    }

    /** Store a validated, immutable-by-digest request for the privileged CLI operator. */
    public function request(string $file,array $options): array
    {
        return $this->exclusive(function()use($file,$options){
            $this->ready();$package=new SitePackage($this->rootPath);
            try {
                $package->read($this->resolve($file));$plan=$this->plan($package,$options);$this->confirm($plan,$options);
                $id=bin2hex(random_bytes(16));$dir=$this->directory('import-requests');
                $record=['id'=>$id,'file'=>basename($file),'options'=>$plan['options']+['plan'=>$plan['plan']],'sha256'=>$package->digest,'state'=>'pending','created_at'=>gmdate(DATE_ATOM)];
                F::json($dir.'/'.$id.'.json',$record,0640);
                return ['ok'=>true,'request'=>$record,'command'=>'php bin/cajeer import:execute --id='.$id];
            }finally{$package->close();}
        });
    }

    public function requests(): array
    {
        $items=[];foreach(glob($this->directory('import-requests').'/*.json')?:[] as $path) if(!is_link($path)&&preg_match('/^[a-f0-9]{32}\.json$/D',basename($path)))$items[]=F::read($path);
        usort($items,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));return $items;
    }

    public function executeRequest(string $id): array
    {
        if(PHP_SAPI!=='cli'||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid import request');
        return $this->exclusive(function()use($id){
            $path=$this->directory('import-requests').'/'.$id.'.json';$request=F::read($path);
            if(($request['state']??'')!=='pending')throw new \RuntimeException('Request already processed; create a new plan');
            $this->filename($request['file']);
            if(!hash_equals($request['sha256'],F::hash($this->resolve($request['file']))))throw new \RuntimeException('Requested package changed');
            $result=$this->apply($request['file'],$request['options']);
            $request['state']=$result['ok']?'completed':'failed';$request['result']=$result;F::json($path,$request,0640);return $result;
        });
    }

    public function upload(string $path,string $name): array
    {
        return $this->exclusive(function()use($path,$name){
            $this->ready();$this->filename($name);$safe=bin2hex(random_bytes(16)).'.'.pathinfo($name,PATHINFO_EXTENSION);
            $dest=$this->directory('imports').'/'.$safe;if(filesize($path)>SitePackage::TOTAL_LIMIT)throw new \InvalidArgumentException('Package too large');F::copy($path,$dest,0640);$package=new SitePackage($this->rootPath);
            try{$package->read($dest);return ['ok'=>true,'file'=>$safe,'sha256'=>$package->digest,'manifest'=>$package->manifest];}
            catch(\Throwable $e){F::remove($dest);throw $e;}finally{$package->close();}
        });
    }

    public function downloadPath(string $file): string
    {
        $this->filename($file);$path=F::path($this->rootPath,'storage/app/exports/'.$file);
        if(!is_file($path))throw new \InvalidArgumentException('Export not found');return $path;
    }

    public function import(string $file,array $options=[]): array
    {
        if(!empty($options['dry_run']))return $this->diff($file,$options)+['dry_run'=>true,'imported'=>[],'skipped'=>['all:dry-run']];
        if(PHP_SAPI!=='cli')return $this->request($file,$options);
        return $this->exclusive(fn()=>$this->apply($file,$options));
    }

    private function apply(string $file,array $options): array
    {
        $this->ready();$package=new SitePackage($this->rootPath);
        try {
            $package->read($this->resolve($file));$plan=$this->plan($package,$options);$this->confirm($plan,$options);
            $result=$this->publicPlan($plan,$file)+['imported'=>[],'skipped'=>$plan['skipped'],'errors'=>[]];
            if(array_sum(array_intersect_key($plan['summary'],array_flip(['create','update'])))===0)return $result+['unchanged'=>true];
            // The same recovery gate as update/backup. No web process receives the backup key.
            $backup=new BackupManager($this->rootPath,$this->config);
            (new DatabaseBackup($this->rootPath,$this->config))->assertRecoveryReady();
            $snapshot=$backup->capture();$marker=F::path($this->rootPath,'storage/app/backup-restore.json');
            $lease='restore-'.bin2hex(random_bytes(8)).'.lock';
            F::json($marker,['id'=>$snapshot['id'],'state'=>'importing','child_lock'=>$lease,'at'=>gmdate(DATE_ATOM)],0640);
            $result['backup_id']=$snapshot['id'];
            DatabaseBackup::useProcessLock($snapshot['target'].'/'.$lease);
            try {
                $work=function()use($plan,$package,&$result){$mask=umask(0002);try{(new \CajeerEngine\Public\RouteIndex($this->rootPath,$this->db))->mutate(fn()=>$this->applyPlan($plan,$package),[],true);$result['imported']=$plan['options']['sections'];}finally{umask($mask);}};
                if($this->databaseMode($plan['options']['sections']))$this->db->transaction($work);else $work();
                $this->db->disconnect();
                $this->report($result+['state'=>'completed']); F::remove($marker);
                return $result;
            } catch(\Throwable $e) {
                $this->db->disconnect();$result['ok']=false;$result['errors'][]=$e->getMessage();
                try{$backup->restoreCaptured($snapshot['id']);$result['rolled_back']=true;F::remove($marker);}
                catch(\Throwable $recovery){$result['rolled_back']=false;$result['errors'][]='Recovery required: '.$recovery->getMessage();}
                $this->report($result+['state'=>'failed']);return $result;
            } finally {DatabaseBackup::useProcessLock(null);}
        }finally{$package->close();}
    }

    private function plan(SitePackage $package,array $options): array
    {
        if(!empty($options['delete_missing_content'])||!empty($options['create_missing_users'])||($options['settings']??'merge')!=='merge')throw new \InvalidArgumentException('Destructive or privileged import is disabled');
        $strategy=$options['strategy']??'fail';if(!in_array($strategy,['fail','skip','update'],true))throw new \InvalidArgumentException('Unknown conflict strategy');
        $data=$package->data; foreach($data as $section=>$value)if(!is_array($value))throw new \InvalidArgumentException('Invalid section: '.$section);
        // Legacy v2 theme configuration was exported under a separate key.
        if(isset($data['theme_config'])&&!isset($data['cms']))$data['cms']=['theme'=>$data['theme_config']];
        $available=array_values(array_intersect(self::SECTIONS,array_keys($data))); if($available===[])throw new \InvalidArgumentException('No portable sections');
        $sections=$this->sections(($options['sections']??[])?:$available);
        if(array_diff($sections,$available))throw new \InvalidArgumentException('Selected section is missing in package');
        $p=['options'=>['sections'=>$sections,'strategy'=>$strategy,'settings'=>'merge'],'sha256'=>$package->digest,'manifest'=>$package->manifest,'summary'=>['create'=>0,'update'=>0,'skip'=>0,'conflict'=>0,'delete'=>0],'sections'=>[],'mapping'=>['content'=>[],'media'=>[],'translations'=>[],'urls'=>[]],'skipped'=>array_values(array_diff(array_keys($data),$sections)),'actions'=>[],'state'=>[]];
        $typeIndex=[];$entryIndex=[];$allIds=[];$groupLocales=[];
        if(in_array('content',$sections,true)||in_array('cms',$sections,true))foreach($this->types->all() as $type){
            $typeIndex[$type['handle']]=$type;
            foreach($this->entries->list($type['handle'],['status'=>'all']) as $entry){$key=$this->entryKey($type['handle'],$entry);$entryIndex[$key]=$entry;$allIds[$entry['id']]=$entry;$groupLocales[$entry['translation_group']??$entry['id']][$entry['locale']]=$entry['id'];}
        }
        $p['state']['content']=[$typeIndex,$entryIndex];
        $mediaIds=[];$mediaHashes=[];
        foreach($this->media->all() as $item){$mediaIds[$item['id']]=$item;$mediaHashes[($item['visibility']??'public').':'.$item['checksum']]=$item;}
        $p['state']['media']=$mediaIds;
        $mediaPlan=[];$seenMedia=[];
        if(in_array('media',$sections,true))foreach($this->rows($data['media']['items']??null,'media.items') as $item){
            $id=$this->id($item['id']??null);if(isset($seenMedia[$id]))throw new \InvalidArgumentException('Duplicate media ID');$seenMedia[$id]=true;
            $hash=$item['checksum']??'';if(!is_string($hash)||!isset($package->files[$hash]))throw new \InvalidArgumentException('Missing media bytes: '.$id);
            $meta=$this->media->inspectImportFile($package->files[$hash],(string)($item['filename']??''));
            if($meta['checksum']!==$hash||isset($item['size'])&&(int)$item['size']!==$meta['size'])throw new \InvalidArgumentException('Media metadata mismatch');
            $visibility=$this->media->visibility($item);$hashKey=$visibility.':'.$hash;$existing=$mediaHashes[$hashKey]??null;
            if($existing!==null){
                $target=$existing; if(!isset($mediaPlan[$target['id']])&&F::hash($this->media->sourcePath($target))!==$hash)throw new \RuntimeException('Target media corrupted');
                $action='skip';
            }else{
                if(isset($mediaIds[$id]))throw new \InvalidArgumentException('Media ID collision: '.$id);
                $relative='import/'.$id.'.'.pathinfo($meta['filename'],PATHINFO_EXTENSION);
                if(file_exists($this->media->sourcePath(['path'=>$relative,'visibility'=>$visibility])))throw new \InvalidArgumentException('Media path collision');
                $target=$meta+['id'=>$id,'path'=>$relative,'url'=>$visibility==='private'?'/api/v1/media/'.$id.'/download':$this->storage->publicUrl($relative),'visibility'=>$visibility,'title'=>(string)($item['title']??''),'alt'=>(string)($item['alt']??'')]+$this->media->metadata($item);
                $mediaPlan[$id]=$target;$mediaHashes[$hashKey]=$target;$mediaIds[$id]=$target;$action='create';
            }
            $p['mapping']['media'][$id]=$target['id'];
            foreach(array_merge(array_values($item['variants']??[]),$item['retired_variants']??[]) as $variant){
                $vp=$variant['path']??'';$vu=$variant['url']??'';
                if(!is_string($vp)||!preg_match('~^variants/[a-f0-9-]{36}/[a-f0-9]{64}/(?:thumbnail|small|medium|large|webp)\.(?:jpg|png|webp)$~D',$vp)||!is_string($vu)||($visibility==='private'?(parse_url($vu,PHP_URL_PATH)!=='/api/v1/media/'.$id.'/download'):!str_ends_with((string)parse_url($vu,PHP_URL_PATH),'/'.$vp))||SafeHtml::url($vu)==='')throw new \InvalidArgumentException('Invalid media variant URL');
                foreach(array_unique($visibility==='private'?[$vu]:[$vu,'/uploads/'.$vp]) as $url){if(isset($p['mapping']['urls'][$url])&&$p['mapping']['urls'][$url]!==$target['url'])throw new \InvalidArgumentException('Ambiguous media variant URL');$p['mapping']['urls'][$url]=$target['url'];}
            }
            $oldPath=(string)($item['path']??''); if($oldPath===''||str_contains($oldPath, '\\')||preg_match('~(?:^|/)(?:\.\.?)(?:/|$)|[\x00-\x20]|^/|//~',$oldPath))throw new \InvalidArgumentException('Invalid media path');
            $oldUrl=(string)($item['url']??'');
            if($oldUrl!==''&& (SafeHtml::url($oldUrl)===''||($visibility==='private'?$oldUrl!=='/api/v1/media/'.$id.'/download':!str_ends_with((string)parse_url($oldUrl,PHP_URL_PATH),'/'.$oldPath))))throw new \InvalidArgumentException('Invalid media URL');
            foreach(array_filter($visibility==='private'?[$oldUrl]:[$oldUrl,'/uploads/'.$oldPath]) as $url){
                if(isset($p['mapping']['urls'][$url])&&$p['mapping']['urls'][$url]!==$target['url'])throw new \InvalidArgumentException('Ambiguous media URL');
                $p['mapping']['urls'][$url]=$target['url'];
            }
            $this->action($p,'media',$id,$action);
        }
        $p['actions']['media']=$mediaPlan;
        $incomingTypes=[];$typePlan=[];$entryPlan=[];$seenIds=[];$seenKeys=[];$groups=[];
        if(in_array('content',$sections,true)){
            foreach($this->rows($data['content']['types']??null,'content.types') as $type){
                $normalized=ContentTypeValidator::normalize($type);$handle=$normalized['handle'];if(isset($incomingTypes[$handle]))throw new \InvalidArgumentException('Duplicate content type');
                $existing=$typeIndex[$handle]??null;$action=$this->decision($existing, $normalized,$strategy);
                // Changing schema under existing records can discard fields. Require explicit schema migration instead.
                if($existing!==null&&($this->canonical($existing['fields'])!==$this->canonical($normalized['fields'])||$this->canonical($existing['blocks'])!==$this->canonical($normalized['blocks'])))$action='conflict';
                $this->action($p,'types',$handle,$action);$incomingTypes[$handle]=$action==='skip'||$action==='conflict'?$existing:$normalized;
                if(in_array($action,['create','update'],true))$typePlan[$handle]=['action'=>$action,'data'=>$normalized];
            }
            if(!is_array($data['content']['entries']??null))throw new \InvalidArgumentException('Invalid content.entries');
            foreach($data['content']['entries'] as $handle=>$rows){
                $type=$incomingTypes[$handle]??$typeIndex[$handle]??null;if(!is_array($type))throw new \InvalidArgumentException('Missing content type: '.$handle);
                foreach($this->rows($rows,'content.entries') as $entry){
                    $id=$this->id($entry['id']??null);if(!is_array($entry['data']??null)||array_diff(array_keys($entry['data']),array_column($type['fields'],'handle')))throw new \InvalidArgumentException('Unknown content fields');$normalized=(new ContentEntryValidator($type))->normalizeForCreate($entry);$key=$this->entryKey($handle,$normalized);
                    if(isset($seenIds[$id])||isset($seenKeys[$key]))throw new \InvalidArgumentException('Duplicate entry identity or locale/slug');$seenIds[$id]=true;$seenKeys[$key]=true;
                    $existing=$entryIndex[$key]??null;
                    if(isset($allIds[$id])&&($existing['id']??null)!==$id)throw new \InvalidArgumentException('Content ID collision: '.$id);
                    $target=$existing['id']??$id;$p['mapping']['content'][$id]=$target;
                    $group=$this->id($entry['translation_group']??$id);
                    if($existing!==null){$g=$existing['translation_group']??$existing['id'];if(isset($groups[$group])&&$groups[$group]!==$g)throw new \InvalidArgumentException('Translation group conflict');$groups[$group]=$g;}
                    $entryPlan[$id]=['type'=>$handle,'id'=>$target,'source'=>$entry,'normalized'=>$normalized,'existing'=>$existing,'group'=>$group];
                }
            }
            foreach($entryPlan as $id=>&$row){$group=$groups[$row['group']]??$row['group'];$locale=$row['normalized']['locale'];$target=$row['id'];if(isset($groupLocales[$group][$locale])&&$groupLocales[$group][$locale]!==$target)throw new \InvalidArgumentException('Translation locale collision');$groupLocales[$group][$locale]=$target;$p['mapping']['translations'][$row['group']]=$group;$row['normalized']['translation_group']=$group;$allIds[$target]=['id'=>$target];}unset($row);
            foreach($entryPlan as $id=>&$row){
                $type=$incomingTypes[$row['type']]??$typeIndex[$row['type']];
                $payload=$row['normalized'];$payload['data']=$this->rewriteFields($type,$payload['data'],$p['mapping'],$allIds,$mediaIds);
                $row['payload']=$payload;$row['action']=$this->decision($row['existing'],$payload,$strategy);
                $this->action($p,'entries',$id,$row['action']);
                $row['history']=[];
                if($row['action']==='create'){
                    $history=$data['content']['revisions'][$id]??$row['source']['revisions']??[];
                    $seenRevisions=[];foreach($this->rows($history,'revisions') as $snapshot){
                        $n=$snapshot['revision']??null;if(!is_int($n)||$n<1||isset($seenRevisions[$n])||!is_array($snapshot['data']??null))throw new \InvalidArgumentException('Invalid historical revision');$seenRevisions[$n]=true;
                        $snapshot['data']=$this->rewrite($snapshot['data']??[],$p['mapping']);$snapshot['translation_group']=$payload['translation_group'];$row['history'][]=$snapshot;}
                }
                unset($row['source'],$row['normalized'],$row['existing'],$row['group']);
            }unset($row);
        }
        $p['actions']['types']=$typePlan;$p['actions']['entries']=$entryPlan;
        if(in_array('cms',$sections,true))$this->planCms($p,$data['cms'],$allIds,$mediaIds,$strategy);
        if(in_array('settings',$sections,true)){
            $existing=$this->settings();$p['state']['settings']=$existing; if($data['settings']!==[]&&$this->types->storage()!=='database')throw new \InvalidArgumentException('Settings import requires database-backed target');
            foreach($data['settings'] as $key=>$value){if(!in_array($key,self::SETTINGS,true)){$p['skipped'][]='setting:'.$key;continue;}
                if(is_array($value)&&array_key_exists('value',$value))$value=$value['value'];
                if(!is_string($value)||strlen($value)>255||($key==='app.locale'&&!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$value)))throw new \InvalidArgumentException('Invalid portable setting: '.$key);
                $action=$this->decision(isset($existing[$key])?['value'=>$existing[$key]]:null,['value'=>$value],$strategy);$this->action($p,'settings',$key,$action);if(in_array($action,['create','update'],true))$p['actions']['settings'][$key]=$value;
            }
        }
        if(array_filter($entryPlan,static fn($row)=>in_array($row['action'],['create','update'],true))&&!in_array($this->config->string('search.default','file'),['file','pgsql','postgresql','postgres'],true))throw new \RuntimeException('Import requires local or PostgreSQL search; rebuild external indexes separately');
        $p['plan']=hash('sha256',$this->canonical([$package->digest,$p['options'],$p['state'],$p['sections'],$p['mapping']]));
        $p['ok']=$p['summary']['conflict']===0;
        return $p;
    }

    private function planCms(array &$p,array $cms,array $ids,array $media,string $strategy): void
    {
        if(isset($cms['theme'])){
            $current=$this->theme->get();$incoming=$this->rewrite($cms['theme'],$p['mapping']);$p['state']['theme']=$current;
            if(!is_array($incoming))throw new \InvalidArgumentException('Invalid theme config');
            $incoming=$this->theme->normalizeForImport($incoming);
            $action=$this->decision($current,$incoming,$strategy);$this->action($p,'cms','theme',$action);if($action==='update')$p['actions']['theme']=$incoming;
        }
        $menus=[];$menuIds=[];foreach($this->navigation->all() as $menu){$menus[$menu['handle']]=$menu;foreach($menu['items'] as $item)$menuIds[$item['id']]=$menu['handle'];}$p['state']['navigation']=$menus;
        $seen=[];
        foreach($this->rows($cms['navigation']??[],'navigation') as $menu){
            $handle=$menu['handle']??'';if(!is_string($handle)||!preg_match('/^[a-z][a-z0-9_]{1,99}$/D',$handle)||isset($seen[$handle]))throw new \InvalidArgumentException('Invalid or duplicate menu');$seen[$handle]=true;
            $items=[];$itemIds=[];foreach($this->rows($menu['items']??null,'navigation.items') as $item){
                $item=$this->rewrite($item,$p['mapping']);if(!empty($item['children']))throw new \InvalidArgumentException('Nested menu import is not supported by current CMS');
                if(!empty($item['entry_id'])&&!isset($ids[$item['entry_id']]))throw new \InvalidArgumentException('Unresolved navigation entry');
                if(!is_string($item['label']??null)||$item['label']===''||!is_string($item['url']??null)||SafeHtml::url($item['url'])==='')throw new \InvalidArgumentException('Invalid navigation item');
                // Stable item identity scoped to menu prevents collisions with other menus.
                $sourceId=$this->id($item['id']??null);if(isset($itemIds[$sourceId]))throw new \InvalidArgumentException('Duplicate menu item ID');$itemIds[$sourceId]=true;
                $item['id']=isset($menuIds[$sourceId])&&$menuIds[$sourceId]!==$handle?$this->stableId('menu:'.$handle.':'.$sourceId):$sourceId;
                if(isset($menuIds[$item['id']])&&$menuIds[$item['id']]!==$handle)throw new \InvalidArgumentException('Menu item identity collision');$menuIds[$item['id']]=$handle;$items[]=['id'=>$item['id'],'label'=>$item['label'],'url'=>$item['url'],'target'=>$item['target']??'_self','entry_type'=>$item['entry_type']??null,'entry_id'=>$item['entry_id']??null,'data'=>$item['data']??[],'children'=>[]];
            }
            $name=trim((string)($menu['name']??$handle));$incoming=['name'=>$name!==''?$name:ucfirst($handle),'items'=>$this->navigation->normalizeItems($items)];$existing=$menus[$handle]??null;if($existing!==null)foreach($existing['items'] as &$i)$i=array_intersect_key($i,array_flip(['id','label','url','target','entry_type','entry_id','data','children']));unset($i);
            $action=$this->decision($existing,$incoming,$strategy);$this->action($p,'navigation',$handle,$action);if(in_array($action,['create','update'],true))$p['actions']['navigation'][$handle]=$incoming;
        }
        $redirects=[];foreach($this->redirects->all() as $r)$redirects[$r['source_path']]=$r;$p['state']['redirects']=$redirects;$seen=[];
        foreach($this->rows($cms['redirects']??[],'redirects') as $r){
            $source=$r['source_path']??'';$target=$this->rewrite($r['target_url']??'',$p['mapping']);
            if(!is_string($source)||!is_string($target)||!str_starts_with($source,'/'))throw new \InvalidArgumentException('Invalid redirect');
            $source=\CajeerEngine\Public\PublicPath::content($source);$status=$r['status_code']??301;
            if($source==='/'||strlen($source)>700||isset($seen[$source])||!in_array($status,[301,302,307,308,410],true))throw new \InvalidArgumentException('Invalid redirect');
            if($status===410)$target='';else {\CajeerEngine\Public\PublicPath::target($target);if($source===$target)throw new \InvalidArgumentException('Redirect cycle');}$seen[$source]=true;
            $incoming=['source_path'=>$source,'target_url'=>$target,'status_code'=>$r['status_code']??301,'enabled'=>filter_var($r['enabled']??true,FILTER_VALIDATE_BOOLEAN)];$existing=$redirects[$source]??null;$action=$this->decision($existing,$incoming,$strategy);$this->action($p,'redirects',$source,$action);if(in_array($action,['create','update'],true))$p['actions']['redirects'][$source]=['data'=>$incoming,'id'=>$existing['id']??$this->stableId('redirect:'.$source)];
        }
    }

    private function applyPlan(array $p,SitePackage $package): void
    {
        $a=$p['actions'];foreach($a['media']??[] as $item)$this->media->importFile($package->files[$item['checksum']],$item);
        foreach($a['types']??[] as $handle=>$row){if($row['action']==='create')$this->types->create($row['data']);else $this->types->update($handle,$row['data']);}
        foreach($a['entries']??[] as $row){
            if($row['action']==='create'){$created=$this->entries->create($row['type'],$row['payload'],$row['id']);if($created['id']!==$row['id']||$created['slug']!==$row['payload']['slug'])throw new \RuntimeException('Entry identity changed');if($row['history']!==[])$this->entries->importHistory($row['type'],$row['id'],$row['history']);}
            elseif($row['action']==='update'){if($this->entries->update($row['type'],$row['id'],$row['payload'])===null)throw new \RuntimeException('Entry update failed');}
        }
        if(isset($a['theme']))$this->theme->save($a['theme']);
        foreach($a['navigation']??[] as $handle=>$menu)$this->navigation->save($handle,$menu['name'],$menu['items']);
        foreach($a['redirects']??[] as $r)$this->redirects->save($r['data'],$r['id']);
        if(isset($a['settings'])){
            $repo=new SettingsRepository($this->db);foreach($a['settings'] as $key=>$value)$repo->set($key,$value,'string');
        }
        // RouteIndex::mutate commits the search projection once for the complete import.
    }

    private function rewriteFields(array $type,array $data,array $map,array $ids,array $media): array
    {
        foreach($type['fields']??[] as $field){$key=$field['handle'];if(!array_key_exists($key,$data)||$data[$key]===null)continue;$v=$data[$key];
            if(in_array($field['type'],['media','relation'],true)){
                $kind=$field['type']==='media'?'media':'content';$valid=$kind==='media'?$media:$ids;
                $resolve=function($id)use($map,$kind,$valid,$key){$id=(string)$id;$target=$map[$kind][$id]??$id;if(!isset($valid[$target]))throw new \InvalidArgumentException('Unresolved '.$kind.' reference in '.$key.': '.$id);return $target;};
                $data[$key]=is_array($v)?array_map($resolve,$v):$resolve($v);
            }elseif($field['type']==='blocks'){
                $defs=array_column($type['blocks']??[],null,'handle');foreach($v as &$block)$block['data']=$this->rewriteFields($defs[$block['handle']],$block['data'],$map,$ids,$media);unset($block);$data[$key]=$v;
            }else $data[$key]=$this->rewrite($v,$map);
        }return $data;
    }
    private function rewrite(mixed $v,array $map): mixed
    {
        if(is_array($v)){foreach($v as $k=>$child)$v[$k]=$this->rewrite($child,$map);return $v;}
        if(!is_string($v))return $v;
        if(isset($map['content'][$v]))return $map['content'][$v];if(isset($map['media'][$v]))return $map['media'][$v];
        if(isset($map['urls'][$v]))return $map['urls'][$v];
        // Match complete URL tokens, not substrings of unrelated URLs or text.
        return preg_replace_callback('~(?:https?://|/)[^\s<>"\'()]+~i',static function($m)use($map){$url=$m[0];$base=preg_split('/[?#]/',$url,2)[0];return isset($map['urls'][$base])?$map['urls'][$base].substr($url,strlen($base)):$url;},$v)??$v;
    }
    private function decision(?array $existing,array $incoming,string $strategy): string
    {
        if($existing===null)return 'create';
        $current=array_intersect_key($existing,$incoming);if($this->canonical($current)===$this->canonical($incoming))return 'skip';
        return $strategy==='fail'?'conflict':$strategy;
    }
    private function canonical(mixed $v): string
    {
        $sort=function(mixed $x)use(&$sort):mixed{if(is_array($x)){if(!array_is_list($x))ksort($x);foreach($x as &$y)$y=$sort($y);unset($y);}return $x;};return SitePackage::encode($sort($v));
    }
    private function action(array &$p,string $section,string $key,string $action): void{$p['sections'][$section][]=['id'=>$key,'action'=>$action];$p['summary'][$action]++;}
    private function publicPlan(array $p,string $file): array{unset($p['actions'],$p['state']);return $p+['file'=>basename($file)];}
    private function confirm(array $p,array $o): void{if(!$p['ok'])throw new \InvalidArgumentException('Resolve import conflicts before apply');if(!is_string($o['plan']??null)||!hash_equals($p['plan'],$o['plan']))throw new \InvalidArgumentException('Plan missing or stale; run import:diff and confirm its plan digest');}
    private function entryKey(string $handle,array $entry): string{return $handle.'|'.$entry['locale'].'|'.$entry['slug'];}
    private function id(mixed $id): string{if(!is_string($id)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id))throw new \InvalidArgumentException('Invalid record UUID');return $id;}
    private function stableId(string $key): string{$h=hash('sha256','cajeer-import:'.$key);return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);}
    private function rows(mixed $rows,string $name): array{if(!is_array($rows)||!array_is_list($rows))throw new \InvalidArgumentException('Invalid list: '.$name);foreach($rows as $row)if(!is_array($row))throw new \InvalidArgumentException('Invalid row: '.$name);return $rows;}
    private function sections(array $sections): array{$sections=$sections?:self::SECTIONS; $sections=array_values(array_unique(array_map(static fn($s)=>$s==='themes'?'cms':$s,$sections)));if(array_diff($sections,self::SECTIONS))throw new \InvalidArgumentException('Only content, media, cms and settings are portable');sort($sections);return $sections;}
    private function settings(): array{if($this->types->storage()!=='database')return []; $out=[];$repo=new SettingsRepository($this->db);foreach(self::SETTINGS as $key){$v=$repo->get($key);if($v!==null)$out[$key]=$v;}return $out;}
    private function databaseMode(array $sections): bool{return getenv('MEDIA_STORAGE')==='database'||$this->types->storage()==='database'||(in_array('cms',$sections,true)&&$this->theme->storage()==='database')||($this->config->string('search.default','file')==='pgsql');}
    private function directory(string $name): string{$p=F::path($this->rootPath,'storage/app/'.$name);F::dir($p,02770);return $p;}
    private function filename(string $name): void{if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.(?:zip|json)$/D',$name))throw new \InvalidArgumentException('Invalid package filename');}
    private function resolve(string $file): string{if(PHP_SAPI==='cli'&&str_contains($file,'/')&&is_file($file)&&!is_link($file))return $file;$this->filename($file);foreach(['imports','exports'] as $dir){$p=F::path($this->rootPath,'storage/app/'.$dir.'/'.$file);if(is_file($p))return $p;}throw new \InvalidArgumentException('Package not found');}
    private function exclusive(callable $work): mixed{if(OperationLock::exclusiveHeld($this->rootPath))return $work();$lock=OperationLock::acquire($this->rootPath,true);try{return $work();}finally{OperationLock::release($lock);}}
    private function ready(): void{
        if(OperationLock::maintenance($this->rootPath))throw new \RuntimeException('Maintenance/recovery active');
        if(is_file($this->rootPath.'/storage/app/installed.lock')&&$this->config->string('app.env','production')==='production'){
            if(strtolower((string)getenv('CONTENT_STORAGE'))==='file'||strtolower((string)getenv('CMS_STORAGE'))==='file'||$this->types->storage()!=='database'||$this->theme->storage()!=='database'||!$this->db->contentCoreReady())throw new \RuntimeException('Production transfer requires available database-backed CMS');
        }
    }
    private function report(array $report): void{F::json($this->directory('import-reports').'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json',$report,0640);}
}
