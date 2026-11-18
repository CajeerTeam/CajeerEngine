<?php

declare(strict_types=1);

namespace CajeerEngine\Migration;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Public\{PublicPath,RouteIndex};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\{OperationFiles,OperationLock};
use PDO;

/** Operator-only, source-aware reconciliation and cutover. Never mutates arbitrary source systems. */
final readonly class MigrationCutover
{
    public function __construct(
        private string $root,
        private DatabaseManager $db,
        private ContentEntryRepository $entries,
        private ContentTypeRepository $types,
    ) {}

    private static function encode(mixed $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private static function digest(mixed $value):string{return hash('sha256',self::encode($value));}
    private function backup():BackupManager{return new BackupManager($this->root,new ConfigRepository($this->root));}
    private function marker():string{return OperationFiles::path($this->root,'storage/app/maintenance.json');}
    private function assertOperator():void
    {
        if(!OperationLock::exclusiveHeld($this->root))throw new \LogicException('An exclusive operator lock is required.');
        if(!$this->db->tableExists('ce_migration_validation')||!$this->db->tableExists('ce_migration_sync_items')||!$this->db->tableExists('ce_migration_sync_runs')||!$this->db->tableExists('ce_migration_cutovers'))
            throw new \RuntimeException('Apply migration 0024 before validation/cutover.');
        if($this->entries->storage()!=='database')throw new \RuntimeException('Migration validation requires database-backed content.');
        if(OperationLock::active($this->root)||is_file(OperationFiles::path($this->root,'storage/app/backup-restore.json'))||is_file(OperationFiles::path($this->root,'storage/app/extensions/deployment.json')))
            throw new \RuntimeException('Update or recovery is pending.');
    }
    private function receipt(string $project,string $source):array
    {
        $table=$source==='dle-db'?'ce_dle_migration_runs':'ce_wordpress_migration_runs';
        if(!in_array($source,['dle-db','wordpress-db','wordpress-rest','wordpress-wxr'],true))throw new \InvalidArgumentException('Unsupported source.');
        $q=$this->db->connection()->prepare('SELECT result FROM '.$table.' WHERE project_id=:project');
        $q->execute(['project'=>$project]);$raw=$q->fetchColumn();
        if(!is_string($raw))throw new \RuntimeException('Complete the content import before reconciliation.');
        $r=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        if(!is_array($r)||!is_array($r['records']??null))throw new \RuntimeException('Corrupt content receipt.');
        return $r;
    }
    public function summary(string $project):?array
    {
        MigrationExecution::validateId($project);
        if(!$this->db->tableExists('ce_migration_validation'))return null;
        $q=$this->db->connection()->prepare('SELECT report FROM ce_migration_validation WHERE project_id=:id');
        $q->execute(['id'=>$project]);$raw=$q->fetchColumn();
        if(!is_string($raw))return null;
        $r=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        unset($r['changes']);
        $q=$this->db->connection()->prepare('SELECT report_sha256,target_url,backup_id,activated_at FROM ce_migration_cutovers WHERE project_id=:id');
        $q->execute(['id'=>$project]);$cutover=$q->fetch(PDO::FETCH_ASSOC);
        if($cutover)$r['cutover']=$cutover;
        $q=$this->db->connection()->prepare('SELECT state,cursor_index,total,backup_id,updated_at FROM ce_migration_sync_runs WHERE project_id=:id');
        $q->execute(['id'=>$project]);$sync=$q->fetch(PDO::FETCH_ASSOC);
        if($sync)$r['synchronization']=$sync;
        return $r;
    }
    /** Pure reconciliation of source inventory against the original content receipt and subsequent sync receipts. */
    public static function reconcile(array $inventory,array $project,array $receipt,callable $lookup,callable $syncReceipt):array
    {
        $baseline=[];$plan=[];
        foreach($project['plan']['records']??[] as $a)$plan[$a['source_type'].':'.$a['source_id']]=$a;
        foreach($receipt['records'] as $row)$baseline[$row['source_type'].':'.$row['source_id']]=$row;
        $source=[];$changes=[];$problems=[];$drift=[];$counts=['source'=>0,'existing'=>0,'new'=>0,'changed'=>0,'missing_source'=>0,'target_missing'=>0,'skipped'=>0];
        $reserved=[];
        $userMap=[];foreach($receipt['users']??[] as $u)if(isset($u['source_id'],$u['target_id'])&&is_string($u['target_id']))$userMap[(string)$u['source_id']]=$u['target_id'];
        foreach($inventory['records']??[] as $r){
            $key=$r['type'].':'.$r['id'];if(isset($source[$key])){$problems[]=['source'=>$key,'code'=>'duplicate_source'];continue;}
            $source[$key]=true;$counts['source']++;
            $rule=$project['mapping']['types'][$r['type']]??null;
            if(!is_array($rule)||($rule['target']??'')===''){$counts['skipped']++;continue;}
            $status=$project['mapping']['statuses'][$r['status']]??'draft';
            if($status==='skip'){$counts['skipped']++;continue;}
            $type=$lookup('type',$rule['target']);
            if($type===null){$problems[]=['source'=>$key,'code'=>'target_type_missing'];continue;}
            try{$payload=MigrationPlanner::payload($r,$rule,$type,$project['mapping']['locale'],$status);}
            catch(\Throwable){$problems[]=['source'=>$key,'code'=>'invalid_mapped_data'];continue;}
            $old=$baseline[$key]??null;$synced=$syncReceipt($key);
            if(($old['action']??null)==='skip'&&$synced===null){$counts['skipped']++;continue;}
            $lastHash=$synced['source_sha256']??($plan[$key]['payload_sha256']??null);
            $hash=self::digest($payload);
            if($old!==null||$synced!==null){
                $counts['existing']++;
                $id=(string)($synced['target_id']??$old['target_id']??'');
                $entry=$lookup('entry',[$rule['target'],$id]);
                if($entry===null){$counts['target_missing']++;$problems[]=['source'=>$key,'code'=>'target_missing'];continue;}
                if(($entry['status']??'')!==($payload['status']??''))$drift[]=['source'=>$key,'code'=>'target_status_differs'];
                if(($entry['slug']??'')!==($payload['slug']??''))$drift[]=['source'=>$key,'code'=>'target_slug_differs'];
                if($lastHash!==null&&hash_equals($lastHash,$hash))continue;
                $counts['changed']++;
                $expected=(int)($synced['revision']??$old['revision']??0);
                if((int)$entry['revision']!==$expected||$entry['slug']!==($payload['slug']??'')){
                    $problems[]=['source'=>$key,'code'=>'editor_revision_or_slug_conflict'];continue;
                }
                $changes[]=['source_type'=>$r['type'],'source_id'=>(string)$r['id'],'action'=>'update','target_type'=>$rule['target'],'target_id'=>$id,'expected_revision'=>$expected,'payload_sha256'=>$hash,'source_author'=>(string)($r['author']??''),'author_id'=>$userMap[(string)($r['author']??'')]??$old['author_id']??null];
            }else{
                $counts['new']++;
                $slug=(string)$payload['slug'];$reservation=$rule['target'].'|'.$payload['locale'].'|'.$slug;
                if(isset($reserved[$reservation])||$lookup('slug',[$rule['target'],$slug])!==null){$problems[]=['source'=>$key,'code'=>'target_slug_collision'];continue;}
                $reserved[$reservation]=true;
                $author=(string)($r['author']??'');
                if($author!==''&&!isset($userMap[$author])){$problems[]=['source'=>$key,'code'=>'unmapped_new_author'];continue;}
                $changes[]=['source_type'=>$r['type'],'source_id'=>(string)$r['id'],'action'=>'create','target_type'=>$rule['target'],'target_id'=>null,'expected_revision'=>null,'payload_sha256'=>$hash,'source_author'=>$author,'author_id'=>$userMap[$author]??null];
            }
        }
        foreach($baseline as $key=>$row)if(!isset($source[$key])&&($row['action']??'')!=='skip'){$counts['missing_source']++;$problems[]=['source'=>$key,'code'=>'missing_at_source'];}
        $warnings=[];
        foreach(($inventory['warnings']??[]) as $key=>$warning){
            $warnings[]=['category'=>is_string($key)?$key:'source','detail'=>substr((string)$warning,0,180)];
            if(in_array($key,['missing_category','missing_parent','taxonomy'],true))$problems[]=['source'=>'inventory','code'=>'source_relation_incomplete'];
        }
        return ['counts'=>$counts,'changes'=>$changes,'problems'=>$problems,'drift'=>$drift,'warnings'=>$warnings];
    }
    private function renderer():\CajeerEngine\Public\PublicPageRenderer
    {
        $config=new ConfigRepository($this->root);
        $templates=$this->root.'/templates';
        return new \CajeerEngine\Public\PublicPageRenderer(
            $this->root,$this->types,$this->entries,
            new \CajeerEngine\Template\CajeerTemplateEngine(new \CajeerEngine\Template\TemplateSandbox($templates),$templates,$this->root.'/storage/cache/templates'),
            new MediaRepository($this->root,new StorageManager($this->root,$config),$this->db),
            new \CajeerEngine\Cms\ThemeConfigRepository($this->root,$this->db),
            new \CajeerEngine\Cms\NavigationRepository($this->root,$this->db),
            new RouteIndex($this->root,$this->db)
        );
    }
    /** Creates a fresh, reviewable report; never changes target content or starts a cutover. */
    public function validate(MigrationCenter $center,string $project):array
    {
        $this->assertOperator();MigrationExecution::validateId($project);
        $snapshot=$center->executionSnapshot($project);$p=$snapshot['project'];$inventory=$snapshot['inventory'];
        if(($p['status']??'')!=='approved'||empty($p['plan']['approved_at']))throw new \RuntimeException('An approved original project is required.');
        if($this->db->tableExists('ce_migration_execution')){
            $state=$this->db->connection()->prepare('SELECT state FROM ce_migration_execution WHERE project_id=:id');$state->execute(['id'=>$project]);
            $phase=$state->fetchColumn();if($phase!==false&&$phase!=='completed')throw new \RuntimeException('Migration batches are not complete.');
        }
        $receipt=$this->receipt($project,$p['source']);
        if(!hash_equals((string)($p['plan']['sha256']??''),(string)($receipt['plan_sha256']??'')))throw new RevisionConflict('Original project and content receipt disagree.');
        $pdo=$this->db->connection();$synced=[];
        $q=$pdo->prepare('SELECT source_type,source_id,source_sha256,target_id,revision FROM ce_migration_sync_items WHERE project_id=:id');$q->execute(['id'=>$project]);
        while($r=$q->fetch(PDO::FETCH_ASSOC))$synced[$r['source_type'].':'.$r['source_id']]=$r;
        $types=[];$entries=[];
        $lookup=function(string $kind,mixed $value)use(&$types,&$entries):?array{
            if($kind==='type')return $types[$value]??= $this->types->find($value);
            if($kind==='slug')return $this->entries->find($value[0],$value[1]);
            $key=$value[0].':'.$value[1];return $entries[$key]??= $this->entries->findById($value[0],$value[1]);
        };
        $result=self::reconcile($inventory,$p,$receipt,$lookup,fn(string $key)=>$synced[$key]??null);
        $users=new \CajeerEngine\Database\Repository\UserRepository($this->db,new \CajeerEngine\Database\Repository\RoleRepository($this->db));
        $checkedUsers=0;
        foreach(($receipt['users']??[]) as $identity){
            if(($identity['action']??'')==='skip'||!is_string($identity['target_id']??null))continue;
            $checkedUsers++;
            if($users->findById($identity['target_id'])===null)$result['problems'][]=['source'=>(string)($identity['source_id']??'unknown'),'code'=>'migrated_account_missing'];
        }
        $result['counts']['users_verified']=$checkedUsers;
        $routes=new RouteIndex($this->root,$this->db);$sitemap=$this->renderer()->sitemap();$sitemapIds=array_fill_keys(array_column($sitemap,'id'),true);
        $verifiedRecords=$receipt['records'];
        foreach($synced as $key=>$row){
            $exists=false;foreach($receipt['records'] as $old)if($old['source_type'].':'.$old['source_id']===$key){$exists=true;break;}
            if(!$exists){[$sourceType,$sourceId]=explode(':',$key,2);$kind=null;foreach($p['mapping']['types'] as $typeKey=>$mapping)if($typeKey===$sourceType)$kind=$mapping['target']??null;
                if($kind!==null&&$kind!=='')$verifiedRecords[]=['source_type'=>$sourceType,'source_id'=>$sourceId,'target_type'=>$kind,'target_id'=>$row['target_id'],'action'=>'create'];
            }
        }
        $unresolvedAssets=[];
        foreach($verifiedRecords as $r){
            if(($r['action']??'')==='skip'||!isset($r['target_id']))continue;
            $entry=$lookup('entry',[$r['target_type'],$r['target_id']]);if(!$entry)continue;
            if(isset($r['author_id'])&&$r['author_id']!==null&&($entry['author_id']??null)!==$r['author_id'])$result['drift'][]=['source'=>$r['source_type'].':'.$r['source_id'],'code'=>'author_assignment_changed'];
            foreach(MediaMigration::missingReferences($entry['data'],[]) as $asset){if(count($unresolvedAssets)<50)$unresolvedAssets[$asset]=true;}
            $route=$routes->forEntry((string)$r['target_id']);
            if($entry['status']==='published'){
                if(($route['id']??'')!==$r['target_id'])$result['problems'][]=['source'=>$r['source_type'].':'.$r['source_id'],'code'=>'missing_public_route'];
                if(!isset($sitemapIds[$r['target_id']])){
                    // noindex pages are intentionally absent from the sitemap.
                    $q=$pdo->prepare('SELECT noindex FROM ce_migration_seo_meta WHERE target_id=:id');$q->execute(['id'=>$r['target_id']]);
                    if((int)$q->fetchColumn()!==1)$result['problems'][]=['source'=>$r['source_type'].':'.$r['source_id'],'code'=>'missing_sitemap_entry'];
                }
            }
        }
        if($unresolvedAssets!==[]){foreach(array_keys($unresolvedAssets) as $asset)$result['problems'][]=['source'=>$asset,'code'=>'unresolved_legacy_media_url'];}
        if($this->db->tableExists('ce_migration_seo_query_urls')){
            $q=$pdo->prepare('SELECT query_key,target_path FROM ce_migration_seo_query_urls WHERE project_id=:id');$q->execute(['id'=>$project]);
            $redirect=new RedirectRepository($this->root,$this->db);
            while($url=$q->fetch(PDO::FETCH_ASSOC))if($redirect->legacyQueryTarget($url['query_key'])!==$url['target_path'])$result['problems'][]=['source'=>$url['query_key'],'code'=>'legacy_query_redirect_broken'];
        }
        $q=$pdo->prepare('SELECT COUNT(*) FROM ce_migration_seo_runs WHERE project_id=:id');$q->execute(['id'=>$project]);
        if((int)$q->fetchColumn()===0)$result['problems'][]=['source'=>'seo','code'=>'seo_stage_not_completed'];
        $media=new MediaRepository($this->root,new StorageManager($this->root,new ConfigRepository($this->root)),$this->db);
        $q=$pdo->prepare('SELECT source_path,media_id,checksum FROM ce_migration_media_files WHERE project_id=:id');$q->execute(['id'=>$project]);
        $mediaCount=0;$badMedia=0;
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $mediaCount++;
            try{$item=$media->find($r['media_id']);$path=$item?$media->sourcePath($item):'';
                if(!$item||!is_file($path)||!hash_equals($r['checksum'],hash_file('sha256',$path)))throw new \RuntimeException('Invalid media');
            }catch(\Throwable){$badMedia++;if($badMedia<=50)$result['problems'][]=['source'=>$r['source_path'],'code'=>'media_missing_or_corrupt'];}
        }
        $result['counts']['media_verified']=$mediaCount-$badMedia;$result['counts']['media_invalid']=$badMedia;
        $result['route_index']=$routes->check();if(!$result['route_index']['ok'])$result['problems'][]=['source'=>'routing','code'=>'route_index_inconsistent'];
        $result['source_sha256']=MigrationPlanner::fingerprint($inventory);
        $result['plan_sha256']=$p['plan']['sha256'];$result['project_id']=$project;
        $result['ready']=count($result['changes'])===0&&count($result['problems'])===0&&count($result['drift'])===0;
        $result['checked_at']=gmdate(DATE_ATOM);
        $signed=$result;unset($signed['checked_at']);$result['sha256']=self::digest($signed);
        $this->db->transaction(function()use($project,$result){
            $pdo=$this->db->connection();$pdo->prepare('DELETE FROM ce_migration_validation WHERE project_id=:id')->execute(['id'=>$project]);
            $q=$pdo->prepare('INSERT INTO ce_migration_validation(project_id,report_sha256,source_sha256,ready,report,checked_at) VALUES(:project,:sha,:source,:ready,:report,:at)');
            $q->execute(['project'=>$project,'sha'=>$result['sha256'],'source'=>$result['source_sha256'],'ready'=>$result['ready']?1:0,'report'=>self::encode($result),'at'=>$result['checked_at']]);
        });
        return $result;
    }

    private function review(string $project,string $sha):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new \InvalidArgumentException('Expected validation SHA-256.');
        $q=$this->db->connection()->prepare('SELECT report,report_sha256 FROM ce_migration_validation WHERE project_id=:id');$q->execute(['id'=>$project]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r||!hash_equals($r['report_sha256'],$sha))throw new RevisionConflict('Validation changed; review a fresh report.');
        return json_decode($r['report'],true,128,JSON_THROW_ON_ERROR);
    }
    private function ownMarker(string $project,string $backup):void
    {
        $path=$this->marker();if(!is_file($path))throw new \RuntimeException('Migration maintenance marker missing.');
        $m=OperationFiles::read($path);
        if(($m['migration_sync_project']??null)!==$project||($m['migration_sync_backup']??null)!==$backup||($m['enabled']??null)!==true)
            throw new \RuntimeException('Maintenance is owned by a different operation.');
    }
    /** Apply an explicitly reviewed delta, with SQL-atomic item receipts and bounded batches. */
    public function synchronize(MigrationCenter $center,string $project,string $sha,int $batch=50,int $maxBatches=0):array
    {
        $this->assertOperator();MigrationExecution::validateId($project);
        if($batch<1||$batch>100||$maxBatches<0||$maxBatches>100000)throw new \InvalidArgumentException('Invalid batch limit.');
        if(!class_exists(\DOMDocument::class))throw new \RuntimeException('DOM extension required for safe synchronization.');
        $report=$this->review($project,$sha);
        if($report['problems']!==[]||$report['drift']!==[])throw new \RuntimeException('Resolve validation blockers and target drift before synchronization.');
        if($report['changes']===[])return ['state'=>'nothing_to_sync','project_id'=>$project];
        $pdo=$this->db->connection();$q=$pdo->prepare('SELECT * FROM ce_migration_sync_runs WHERE project_id=:id');$q->execute(['id'=>$project]);$run=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if($run===null){
            if(OperationLock::maintenance($this->root))throw new \RuntimeException('Another maintenance operation is active.');
            // The report must still describe the current source and target before any backup or write.
            $fresh=$this->validate($center,$project);
            if(!hash_equals($fresh['sha256'],$sha))throw new RevisionConflict('Source or target changed since validation.');
            $backup=$this->backup()->capture();$this->backup()->verify($backup['id']);
            OperationFiles::json($this->marker(),['enabled'=>true,'migration_sync_project'=>$project,'migration_sync_backup'=>$backup['id'],'message'=>'Incremental migration in progress; validate and cutover or restore','since'=>gmdate(DATE_ATOM)],0644);
            $q=$pdo->prepare('INSERT INTO ce_migration_sync_runs(project_id,report_sha256,source_sha256,backup_id,cursor_index,total,state,updated_at) VALUES(:id,:sha,:source,:backup,0,:total,:state,:at)');
            $q->execute(['id'=>$project,'sha'=>$sha,'source'=>$report['source_sha256'],'backup'=>$backup['id'],'total'=>count($report['changes']),'state'=>'running','at'=>gmdate(DATE_ATOM)]);
            $run=['report_sha256'=>$sha,'source_sha256'=>$report['source_sha256'],'backup_id'=>$backup['id'],'cursor_index'=>0,'total'=>count($report['changes']),'state'=>'running'];
        }else{
            if(!hash_equals($run['report_sha256'],$sha)||!hash_equals($run['source_sha256'],$report['source_sha256']))throw new RevisionConflict('Already synchronized under a different validation; finish/recover first.');
            if($run['state']==='completed')return ['project_id'=>$project,'state'=>'completed','backup_id'=>$run['backup_id'],'total'=>(int)$run['total']];
            $this->ownMarker($project,$run['backup_id']);$this->backup()->verify($run['backup_id']);
        }
        $snapshot=$center->executionSnapshot($project);$p=$snapshot['project'];$inventory=$snapshot['inventory'];
        if(!hash_equals(MigrationPlanner::fingerprint($inventory),$report['source_sha256']))throw new RevisionConflict('Source changed during synchronization.');
        $bySource=[];foreach($inventory['records'] as $r)$bySource[$r['type'].':'.$r['id']]=$r;
        $done=0;$writer=new RouteIndex($this->root,$this->db);
        while((int)$run['cursor_index']<(int)$run['total']&&($maxBatches===0||$done<$maxBatches)){
            $from=(int)$run['cursor_index'];$upto=min((int)$run['total'],$from+$batch);
            $writer->mutate(function()use($pdo,$report,$p,$bySource,$project,$from,$upto,$sha){
                $q=$pdo->prepare('SELECT cursor_index,state,report_sha256 FROM ce_migration_sync_runs WHERE project_id=:id');$q->execute(['id'=>$project]);$state=$q->fetch(PDO::FETCH_ASSOC);
                if(!$state||(int)$state['cursor_index']!==$from||!in_array($state['state'],['running','paused'],true)||!hash_equals($state['report_sha256'],$sha))throw new RevisionConflict('Stale synchronization cursor.');
                for($i=$from;$i<$upto;$i++){
                    $action=$report['changes'][$i];$key=$action['source_type'].':'.$action['source_id'];$source=$bySource[$key]??throw new RevisionConflict('Source record removed.');
                    $rule=$p['mapping']['types'][$source['type']];$type=$this->types->find($rule['target'])??throw new RevisionConflict('Content type removed.');
                    $status=$p['mapping']['statuses'][$source['status']]??'draft';
                    $payload=MigrationPlanner::payload($source,$rule,$type,$p['mapping']['locale'],$status);
                    if(!hash_equals(self::digest($payload),$action['payload_sha256']))throw new RevisionConflict('Normalized source changed.');
                    $target=$action['target_id'];
                    if($target!==null){$existing=$this->entries->findById($rule['target'],$target);
                        if(!$existing||(int)$existing['revision']!==(int)$action['expected_revision'])throw new RevisionConflict('Editor changed target since validation.');
                    }elseif($this->entries->find($rule['target'],$payload['slug'])!==null)throw new RevisionConflict('Target slug now exists.');
                    $date=$p['source']==='dle-db'?ContentMigrationImporter::date((string)($source['fields']['date']??'')):WordPressContent::date((string)($source['fields']['meta:wp_date_gmt']??''));
                    $entry=$this->entries->importMigrationEntry($rule['target'],$payload,$target,$action['author_id'],$date);
                    $pdo->prepare('DELETE FROM ce_migration_sync_items WHERE project_id=:id AND source_type=:type AND source_id=:source')->execute(['id'=>$project,'type'=>$action['source_type'],'source'=>$action['source_id']]);
                    $q=$pdo->prepare('INSERT INTO ce_migration_sync_items(project_id,source_type,source_id,source_sha256,target_id,revision,updated_at) VALUES(:id,:type,:source,:hash,:target,:rev,:at)');
                    $q->execute(['id'=>$project,'type'=>$action['source_type'],'source'=>$action['source_id'],'hash'=>$action['payload_sha256'],'target'=>$entry['id'],'rev'=>$entry['revision'],'at'=>gmdate(DATE_ATOM)]);
                }
                $q=$pdo->prepare('UPDATE ce_migration_sync_runs SET cursor_index=:next,state=:state,updated_at=:at WHERE project_id=:id AND cursor_index=:previous');
                $q->execute(['next'=>$upto,'state'=>$upto===count($report['changes'])?'completed':'running','at'=>gmdate(DATE_ATOM),'id'=>$project,'previous'=>$from]);
                if($q->rowCount()!==1)throw new RevisionConflict('Synchronization cursor lost.');
            });
            $run['cursor_index']=$upto;$run['state']=$upto===(int)$run['total']?'completed':'running';$done++;
        }
        return ['project_id'=>$project,'state'=>$run['state'],'cursor_index'=>(int)$run['cursor_index'],'total'=>(int)$run['total'],'backup_id'=>$run['backup_id'],'maintenance'=>true];
    }
    /** The final gate records the activated site, after a fresh source and target re-check. */
    public function cutover(MigrationCenter $center,string $project,string $sha,string $target):array
    {
        $this->assertOperator();MigrationExecution::validateId($project);
        $configured=PublicPath::base((new ConfigRepository($this->root))->string('app.url'));
        $expected=PublicPath::base($target);
        if(!str_starts_with($expected,'https://')||preg_match('~^https://(?:localhost|127\.0\.0\.1)(?::|$)~i',$expected))throw new \InvalidArgumentException('Cutover requires the production HTTPS target, not a local development address.');
        if($expected!==$configured)throw new \RuntimeException('Target does not match the configured public site URL. Update APP_URL/deployment first.');
        $prior=$this->db->connection()->prepare('SELECT report_sha256,target_url,backup_id,activated_at FROM ce_migration_cutovers WHERE project_id=:id');$prior->execute(['id'=>$project]);$activated=$prior->fetch(PDO::FETCH_ASSOC);
        if($activated){if(!hash_equals($activated['report_sha256'],$sha)||$activated['target_url']!==$configured)throw new RevisionConflict('Cutover already activated with a different report.');if(is_file($this->marker())){try{$this->ownMarker($project,$activated['backup_id']);OperationFiles::remove($this->marker());}catch(\RuntimeException){/* unrelated marker remains untouched */}}return ['state'=>'activated','project_id'=>$project]+$activated;}
        $current=$this->review($project,$sha);
        if(!$current['ready'])throw new \RuntimeException('Validation is not ready for cutover.');
        $q=$this->db->connection()->prepare('SELECT * FROM ce_migration_sync_runs WHERE project_id=:id');$q->execute(['id'=>$project]);$sync=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if($sync!==null&&$sync['state']!=='completed')throw new \RuntimeException('Incremental synchronization is still pending.');
        if($sync===null&&OperationLock::maintenance($this->root))throw new \RuntimeException('An unrelated maintenance operation is active.');
        if($sync!==null)$this->ownMarker($project,$sync['backup_id']);
        $fresh=$this->validate($center,$project);
        if(!$fresh['ready']||!hash_equals($fresh['sha256'],$sha))throw new RevisionConflict('Source or target changed; validate again before cutover.');
        $routes=new RouteIndex($this->root,$this->db);
        if(!$routes->check()['ok']||!str_contains($this->renderer()->sitemapXml($configured),'<'))throw new \RuntimeException('Route index or sitemap verification failed.');
        $backupId=$sync['backup_id']??null;
        if($backupId===null){$backup=$this->backup()->capture();$this->backup()->verify($backup['id']);$backupId=$backup['id'];}
        else $this->backup()->verify($backupId);
        $q=$this->db->connection()->prepare('INSERT INTO ce_migration_cutovers(project_id,report_sha256,target_url,backup_id,activated_at) VALUES(:id,:sha,:url,:backup,:at)');
        $q->execute(['id'=>$project,'sha'=>$sha,'url'=>$configured,'backup'=>$backupId,'at'=>gmdate(DATE_ATOM)]);
        if($sync!==null){$this->ownMarker($project,$backupId);OperationFiles::remove($this->marker());}
        return ['state'=>'activated','project_id'=>$project,'target_url'=>$configured,'report_sha256'=>$sha,'backup_id'=>$backupId,'dns_changed'=>false,'traffic_note'=>'Route site DNS/reverse proxy to target with your infrastructure tooling; no external DNS was changed.'];
    }
    /** Destructive full-instance restore, deliberately leaves the site offline. */
    public function rollbackSync(string $project,string $backupId):array
    {
        $this->assertOperator();MigrationExecution::validateId($project);
        $q=$this->db->connection()->prepare('SELECT backup_id FROM ce_migration_sync_runs WHERE project_id=:id');$q->execute(['id'=>$project]);$recorded=$q->fetchColumn();
        // Handle crash window after verified snapshot/maintenance but before first DB run row.
        if($recorded!==false&&(!is_string($recorded)||!hash_equals($recorded,$backupId)))throw new \RuntimeException('Synchronization backup does not match.');
        $this->ownMarker($project,$backupId);$backup=$this->backup();$backup->verify($backupId);
        OperationFiles::json($this->marker(),['enabled'=>true,'migration_sync_project'=>$project,'migration_sync_backup'=>$backupId,'rollback_pending'=>true,'message'=>'Migration sync restore in progress','since'=>gmdate(DATE_ATOM)],0644);
        $backup->restoreCaptured($backupId);
        OperationFiles::json($this->marker(),['enabled'=>true,'restored_backup'=>$backupId,'message'=>'Full site restored; operator must verify before maintenance:off','since'=>gmdate(DATE_ATOM)],0644);
        return ['state'=>'restored','project_id'=>$project,'backup_id'=>$backupId,'maintenance'=>true];
    }
}
