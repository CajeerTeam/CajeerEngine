<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\{OperationFiles as F,OperationLock};

/** CLI-only, checkpointed import coordinator. No user-supplied PHP or SQL is evaluated. */
final readonly class MigrationExecution
{
    public function __construct(
        private string $root,
        private DatabaseManager $db,
        private ContentEntryRepository $entries,
        private ContentTypeRepository $types,
        private MigrationPlanner $planner,
    ) {}

    public static function validateId(string $id):string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid project ID.');
        return $id;
    }
    private function marker():string{return F::path($this->root,'storage/app/maintenance.json');}
    public function pausePath(string $id):string{return F::path($this->root,'storage/app/migration/'.self::validateId($id).'.pause');}
    private function backup():BackupManager{return new BackupManager($this->root,new ConfigRepository($this->root));}
    public function status(string $project):?array
    {
        self::validateId($project);
        if(!$this->db->tableExists('ce_migration_execution'))return null;
        $q=$this->db->connection()->prepare('SELECT project_id,source,plan_sha256,backup_id,total,cursor_index,state,started_at,updated_at FROM ce_migration_execution WHERE project_id=:id');
        $q->execute(['id'=>$project]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row)return null;
        $row['total']=(int)$row['total'];$row['cursor_index']=(int)$row['cursor_index'];
        $row['percent']=$row['total']===0?100:round(100*$row['cursor_index']/$row['total'],2);
        return $row;
    }
    public function requestPause(string $id):void
    {
        $state=$this->status($id);
        if($state===null||$state['state']==='completed')throw new \RuntimeException('No active migration to pause.');
        F::write($this->pausePath($id),"pause\n");
    }
    private function assertMaintenance(string $project,string $backup):void
    {
        $path=$this->marker();
        if(!is_file($path))throw new \RuntimeException('Migration maintenance marker missing; refuse unsafe resume/rollback.');
        $m=F::read($path);
        if(($m['migration_project']??null)!==$project||($m['migration_backup']??null)!==$backup||($m['enabled']??null)!==true)
            throw new \RuntimeException('Maintenance belongs to another operation; refusing migration.');
    }
    private function guard(bool $needsTables=true):void
    {
        if(!OperationLock::exclusiveHeld($this->root))throw new \LogicException('Exclusive operator operation lock required.');
        if(OperationLock::active($this->root)||is_file(F::path($this->root,'storage/app/backup-restore.json'))||is_file(F::path($this->root,'storage/app/extensions/deployment.json')))
            throw new \RuntimeException('Update, restore or deployment pending.');
        if($this->entries->storage()!=='database'||(new \CajeerEngine\Cms\ThemeConfigRepository($this->root,$this->db))->storage()!=='database')
            throw new \RuntimeException('Checkpointed execution requires SQL content and CMS storage.');
        if($needsTables&&!class_exists(\DOMDocument::class))throw new \RuntimeException('PHP DOM is required for safe import.');
        if($needsTables&&(!$this->db->tableExists('ce_migration_execution')||!$this->db->tableExists('ce_migration_execution_items')))
            throw new \RuntimeException('Apply SQL migration 0023 first.');
    }
    /** This method is called only under an exclusive operation lock. */
    public function run(MigrationCenter $center,string $project,string $sha,int $batch=50,int $maxBatches=0):array
    {
        $this->guard();self::validateId($project);
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||$batch<1||$batch>100||$maxBatches<0||$maxBatches>100000)
            throw new \InvalidArgumentException('Invalid approved hash or batch bounds.');
        $state=$this->status($project);
        if($state!==null){
            if(!hash_equals($state['plan_sha256'],$sha))throw new RevisionConflict('Already started with a different plan hash.');
            if($state['state']==='completed'){
                $this->cleanMarker($project,$state['backup_id']);
                return $state;
            }
            $this->assertMaintenance($project,$state['backup_id']);
            $this->backup()->verify($state['backup_id']);
        }else{
            if(OperationLock::maintenance($this->root))throw new \RuntimeException('Maintenance must be off before starting a new import.');
        }
        $snapshot=$center->executionSnapshot($project);
        $p=$snapshot['project'];$inventory=$snapshot['inventory'];
        if($p['status']!=='approved'||empty($p['plan']['approved_at'])||!($p['plan']['approvable']??false)||!hash_equals($p['plan']['sha256']??'',$sha))
            throw new RevisionConflict('Migration plan must be approved and unchanged.');
        if(!hash_equals($p['plan']['source_sha256'],MigrationPlanner::fingerprint($inventory)))
            throw new RevisionConflict('Source inventory changed since approval; review plan before continuing.');
        $count=count($p['plan']['records']);
        if($state===null){
            // Target fingerprint check is expensive but mandatory exactly once, before taking the backup.
            $fresh=$this->planner->plan($inventory,$p['mapping']);
            if(!hash_equals($fresh['sha256'],$sha))throw new RevisionConflict('Target or source changed; rerun Dry Run.');
            $old=(new ContentMigrationImporter($this->root,$this->db,$this->types,$this->entries,$this->planner))->receipt($project,$p['source']);
            if($old!==null)throw new RevisionConflict('Project was already imported using the legacy executor.');
            $backup=$this->backup()->capture();
            $this->backup()->verify($backup['id']);
            F::json($this->marker(),['enabled'=>true,'migration_project'=>$project,'migration_backup'=>$backup['id'],'message'=>'Checkpointed migration in progress; operator resume or rollback required','since'=>gmdate(DATE_ATOM)],0644);
            $this->db->transaction(function()use($p,$sha,$count,$backup){
                $q=$this->db->connection()->prepare('INSERT INTO ce_migration_execution(project_id,source,plan_sha256,source_sha256,backup_id,total,cursor_index,state,started_at,updated_at) VALUES(:id,:source,:sha,:source_sha,:backup,:total,0,:state,:at,:at2)');
                $q->execute(['id'=>$p['id'],'source'=>$p['source'],'sha'=>$sha,'source_sha'=>$p['plan']['source_sha256'],'backup'=>$backup['id'],'total'=>$count,'state'=>'running','at'=>gmdate(DATE_ATOM),'at2'=>gmdate(DATE_ATOM)]);
            });
            $state=$this->status($project);
        }elseif($state['total']!==$count)throw new RevisionConflict('Plan record count differs from saved checkpoint.');
        F::remove($this->pausePath($project));
        $writer=new ContentMigrationImporter($this->root,$this->db,$this->types,$this->entries,$this->planner);
        $performed=0;
        while($state!==null&&$state['state']!=='completed'){
            if($performed>0&&is_file($this->pausePath($project)))break;
            if($maxBatches!==0&&$performed>=$maxBatches)break;
            $writer->executeBatch($p,$inventory,$state['cursor_index'],$batch);
            $performed++;
            $state=$this->status($project);
        }
        if($state!==null&&$state['state']==='completed')$this->cleanMarker($project,$state['backup_id']);
        elseif($state!==null){
            $q=$this->db->connection()->prepare("UPDATE ce_migration_execution SET state='paused',updated_at=:at WHERE project_id=:id AND state='running'");
            $q->execute(['id'=>$project,'at'=>gmdate(DATE_ATOM)]);
            $state=$this->status($project);
        }
        return $state??throw new \RuntimeException('Checkpoint disappeared.');
    }
    private function cleanMarker(string $project,string $backup):void
    {
        if(is_file($this->marker())){$this->assertMaintenance($project,$backup);F::remove($this->marker());}
        F::remove($this->pausePath($project));
    }
    /** Destructive rollback of the full, verified pre-import snapshot, not individual rows. */
    public function rollback(string $project,string $expectedBackup):array
    {
        $this->guard(false);
        try{$state=$this->status($project);}catch(\Throwable){$state=null;}
        // A crash can occur after creating maintenance but before the first SQL checkpoint,
        // or midway through restoring the target database. The verified backup remains usable.
        if($state!==null&&!hash_equals($state['backup_id'],$expectedBackup))throw new \RuntimeException('Backup ID confirmation does not match.');
        $this->assertMaintenance($project,$expectedBackup);
        $backup=$this->backup();$backup->verify($expectedBackup);
        // Maintenance deliberately remains ON after restore; operator validates before reopening.
        F::json($this->marker(),['enabled'=>true,'message'=>'Migration rollback in progress; maintain offline state until verification','since'=>gmdate(DATE_ATOM),'migration_project'=>$project,'migration_backup'=>$expectedBackup,'rollback_pending'=>true],0644);
        try{$out=$backup->restoreCaptured($expectedBackup);}catch(\Throwable $e){
            // Never reopen traffic automatically after a failed or partial recovery.
            throw new \RuntimeException('Restore failed; maintenance is still active. Use backup recovery procedures.',0,$e);
        }
        F::remove($this->pausePath($project));
        F::json($this->marker(),['enabled'=>true,'message'=>'Migration restored; verify the site then run maintenance:off','since'=>gmdate(DATE_ATOM),'restored_backup'=>$expectedBackup],0644);
        return ['restored'=>true,'backup_id'=>$expectedBackup,'maintenance'=>true,'backup'=>$out];
    }
}
