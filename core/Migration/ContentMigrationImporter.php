<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\RouteIndex;

/** A single bounded content migration batch. Content, projections and receipt commit together. */
readonly class ContentMigrationImporter
{
    public function __construct(private string $root,private DatabaseManager $db,private ContentTypeRepository $types,private ContentEntryRepository $entries,private MigrationPlanner $planner){}

    private static function table(string $source):string
    {
        return match($source){'dle-db'=>'ce_dle_migration_runs','wordpress-db','wordpress-rest','wordpress-wxr'=>'ce_wordpress_migration_runs',default=>throw new \InvalidArgumentException('Unsupported migration source.')};
    }
    public function receipt(string $project,string $source='dle-db'):?array
    {
        if($this->entries->storage()!=='database')return null;
        // Discovery continues to work before the new migration is applied.
        $table=self::table($source);if(!$this->db->tableExists($table))return null;
        $s=$this->db->connection()->prepare('SELECT result FROM '.$table.' WHERE project_id=:id');$s->execute(['id'=>$project]);$r=$s->fetchColumn();
        return is_string($r)?json_decode($r,true,64,JSON_THROW_ON_ERROR):null;
    }
    public function execute(array $project,array $inventory):array
    {
        $source=$project['source'];$table=self::table($source);$wp=$source!=='dle-db';
        if($this->entries->storage()!=='database'||(new \CajeerEngine\Cms\ThemeConfigRepository($this->root,$this->db))->storage()!=='database')throw new \RuntimeException('Content import requires database-backed content and CMS storage.');
        if(!$this->db->tableExists($table))throw new \RuntimeException('Run database migrations before content import.');
        $plan=$project['plan']??[];
        if($project['status']!=='approved'||empty($plan['approved_at'])||empty($plan['approvable']))throw new \InvalidArgumentException('An approved migration plan is required.');
        $id=$project['id'];
        if($prior=$this->receipt($id,$source)){if(!hash_equals($prior['plan_sha256'],$plan['sha256']))throw new RevisionConflict('Project already executed with a different plan.');return $prior;}
        if(!class_exists(\DOMDocument::class))throw new \RuntimeException('PHP DOM is required for safe HTML import.');
        return (new RouteIndex($this->root,$this->db))->mutate(function()use($project,$inventory,$plan,$id,$source,$table,$wp){
            if($prior=$this->receipt($id,$source)){if(!hash_equals($prior['plan_sha256'],$plan['sha256']))throw new RevisionConflict('Project already executed with a different plan.');return $prior;}
            // RouteIndex owns the target SQL writer lock and transaction, including projection rebuild.
            $fresh=$this->planner->plan($inventory,$project['mapping']);
            if(!hash_equals($plan['sha256'],$fresh['sha256']))throw new RevisionConflict('Source or target changed; run Dry Run and approve again.');
            if(!$this->db->tableExists('ce_migration_identities'))throw new \RuntimeException('Run identity migration 0020 before content import.');
            $roles=new \CajeerEngine\Database\Repository\RoleRepository($this->db);
            $identities=(new IdentityMigration($this->db,new \CajeerEngine\Database\Repository\UserRepository($this->db,$roles),$roles))->import($id,$inventory,$fresh['users']);
            $records=[];foreach($inventory['records'] as $r)$records[$r['type'].':'.$r['id']]=$r;
            $result=['project_id'=>$id,'plan_sha256'=>$plan['sha256'],'completed_at'=>gmdate(DATE_ATOM),'counts'=>['create'=>0,'update'=>0,'skip'=>0],'records'=>[],'users'=>$identities['users'],'warnings'=>[$wp?'Media files, comments, menus, standalone taxonomies, page hierarchy and redirects are deferred. Historical dates use source GMT where available; local-only dates are not written.':'Media files, comments, standalone taxonomies and redirects are not imported by this release. DLE source timestamps are interpreted as UTC.']];
            foreach($fresh['records'] as $a){
                $key=$a['source_type'].':'.$a['source_id'];$r=$records[$key]??throw new \LogicException('Missing source record.');
                if(!$wp&&!in_array($a['source_type'],['post','page'],true))throw new \InvalidArgumentException('Unexpected DLE content type.');
                $row=['source_type'=>$a['source_type'],'source_id'=>$a['source_id'],'target_type'=>$a['target_type'],'target_id'=>$a['target_id'],'action'=>$a['action'],'slug'=>$a['slug'],'source_author'=>$r['author'],'source_date'=>$r['fields']['date']];
                if($a['action']!=='skip'){
                    if(!in_array($a['action'],['create','update'],true))throw new \LogicException('Unresolved migration action.');
                    $rule=$project['mapping']['types'][$r['type']];$type=$this->types->find($rule['target'])??throw new RevisionConflict('Target type disappeared.');
                    $status=$project['mapping']['statuses'][$r['status']]??'draft';
                    $payload=MigrationPlanner::payload($r,$rule,$type,$project['mapping']['locale'],$status);$payload['slug']=$a['slug'];
                    if(!hash_equals($a['payload_sha256'],MigrationPlanner::fingerprint($payload)))throw new RevisionConflict('Payload differs from approved plan.');
                    $date=$wp?WordPressContent::date($r['fields']['meta:wp_date_gmt']??''):self::date($r['fields']['date']);
                    $author=$identities['map'][$r['author']]??$a['author_id'];
                    $entry=$this->entries->importMigrationEntry($rule['target'],$payload,$a['action']==='update'?$a['target_id']:null,$author,$date,$a['action']==='update'?($a['expected_revision']??null):null);
                    $row['target_id']=$entry['id'];$row['slug']=$entry['slug'];$row['author_id']=$author;$row['revision']=$entry['revision'];
                    if($r['fields']['date']!==''&&$date===null)$result['warnings'][]='Missing or invalid historical UTC date for '.$key.'; target timestamp retained.';
                }
                $result['counts'][$a['action']]++;$result['records'][]=$row;
            }
            $q=$this->db->connection()->prepare('INSERT INTO '.$table.'(project_id,plan_sha256,completed_at,result) VALUES(:id,:sha,:date,:result)');
            $q->execute(['id'=>$id,'sha'=>$plan['sha256'],'date'=>$result['completed_at'],'result'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            return $result;
        });
    }
    /** A checkpoint, its corresponding content changes and the final receipt commit atomically. */
    public function executeBatch(array $project,array $inventory,int $offset,int $limit):array
    {
        if(!\CajeerEngine\Update\OperationLock::exclusiveHeld($this->root))throw new \LogicException('Exclusive migration lease required.');
        $id=$project['id'];$plan=$project['plan'];$source=$project['source'];$table=self::table($source);
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Batch must contain 1–100 records.');
        if(!class_exists(\DOMDocument::class))throw new \RuntimeException('PHP DOM required for safe HTML import.');
        $actions=$plan['records'];$total=count($actions);
        if($offset<0||$offset>$total)throw new \InvalidArgumentException('Invalid checkpoint cursor.');
        $records=[];foreach($inventory['records'] as $r)$records[$r['type'].':'.$r['id']]=$r;
        return (new RouteIndex($this->root,$this->db))->mutate(function()use($project,$inventory,$id,$plan,$source,$table,$offset,$limit,$actions,$total,$records){
            $pdo=$this->db->connection();
            $q=$pdo->prepare('SELECT cursor_index,total,plan_sha256,source,identities,state FROM ce_migration_execution WHERE project_id=:id');
            $q->execute(['id'=>$id]);$checkpoint=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$checkpoint||!in_array($checkpoint['state'],['running','paused'],true)||!hash_equals($checkpoint['plan_sha256'],$plan['sha256'])||$checkpoint['source']!==$source||(int)$checkpoint['total']!==$total||(int)$checkpoint['cursor_index']!==$offset)
                throw new RevisionConflict('Execution checkpoint was changed or became stale.');
            if($checkpoint['identities']===null){
                if($offset!==0)throw new \RuntimeException('Identity checkpoint missing.');
                $roles=new \CajeerEngine\Database\Repository\RoleRepository($this->db);
                $identities=(new IdentityMigration($this->db,new \CajeerEngine\Database\Repository\UserRepository($this->db,$roles),$roles))->import($id,$inventory,$plan['users']);
            }else $identities=json_decode($checkpoint['identities'],true,64,JSON_THROW_ON_ERROR);
            $finish=min($total,$offset+$limit);
            $insert=$pdo->prepare('INSERT INTO ce_migration_execution_items(project_id,ordinal,source_type,source_id,target_id,result) VALUES(:project,:ordinal,:type,:source,:target,:result)');
            for($i=$offset;$i<$finish;$i++){
                $a=$actions[$i];$key=$a['source_type'].':'.$a['source_id'];$r=$records[$key]??throw new RevisionConflict('Approved record disappeared.');
                $row=['source_type'=>$a['source_type'],'source_id'=>$a['source_id'],'target_type'=>$a['target_type'],'target_id'=>$a['target_id'],'action'=>$a['action'],'slug'=>$a['slug'],'source_author'=>$r['author'],'source_date'=>$r['fields']['date']];
                if($a['action']!=='skip'){
                    if(!in_array($a['action'],['create','update'],true))throw new \LogicException('Unresolved planned action.');
                    $rule=$project['mapping']['types'][$r['type']]??throw new RevisionConflict('Source mapping disappeared.');
                    $type=$this->types->find($rule['target'])??throw new RevisionConflict('Target type disappeared.');
                    $status=$project['mapping']['statuses'][$r['status']]??'draft';
                    $payload=MigrationPlanner::payload($r,$rule,$type,$project['mapping']['locale'],$status);$payload['slug']=$a['slug'];
                    if(!hash_equals($a['payload_sha256'],MigrationPlanner::fingerprint($payload)))throw new RevisionConflict('Approved payload changed for '.$key.'.');
                    $date=$source==='dle-db'?self::date($r['fields']['date']):WordPressContent::date((string)($r['fields']['meta:wp_date_gmt']??''));
                    $author=$identities['map'][$r['author']]??$a['author_id'];
                    $entry=$this->entries->importMigrationEntry($rule['target'],$payload,$a['action']==='update'?$a['target_id']:null,$author,$date,$a['action']==='update'?($a['expected_revision']??null):null);
                    $row['target_id']=$entry['id'];$row['slug']=$entry['slug'];$row['author_id']=$author;$row['revision']=$entry['revision'];
                }
                $insert->execute(['project'=>$id,'ordinal'=>$i,'type'=>$a['source_type'],'source'=>$a['source_id'],'target'=>$row['target_id'],'result'=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            }
            $complete=$finish===$total;
            $newStatus=$complete?'completed':'running';
            $q=$pdo->prepare('UPDATE ce_migration_execution SET cursor_index=:cursor,state=:state,identities=:identities,updated_at=:at WHERE project_id=:id AND cursor_index=:previous');
            $q->execute(['cursor'=>$finish,'state'=>$newStatus,'identities'=>json_encode($identities,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'at'=>gmdate(DATE_ATOM),'id'=>$id,'previous'=>$offset]);
            if($q->rowCount()!==1)throw new RevisionConflict('Execution cursor was concurrently updated.');
            if($complete){
                $rows=$pdo->prepare('SELECT result FROM ce_migration_execution_items WHERE project_id=:id ORDER BY ordinal');$rows->execute(['id'=>$id]);
                $result=['project_id'=>$id,'plan_sha256'=>$plan['sha256'],'completed_at'=>gmdate(DATE_ATOM),'counts'=>['create'=>0,'update'=>0,'skip'=>0],'records'=>[],'users'=>$identities['users'],'warnings'=>['Imported in checkpointed SQL batches. Use the verified pre-import backup for full rollback; media and SEO are separate stages.']];
                while($raw=$rows->fetchColumn()){$row=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$result['counts'][$row['action']]++;$result['records'][]=$row;}
                if(count($result['records'])!==$total)throw new \RuntimeException('Missing execution journal items; rollback required.');
                $receipt=$pdo->prepare('INSERT INTO '.$table.'(project_id,plan_sha256,completed_at,result) VALUES(:id,:sha,:at,:result)');
                $receipt->execute(['id'=>$id,'sha'=>$plan['sha256'],'at'=>$result['completed_at'],'result'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            }
            return ['project_id'=>$id,'cursor'=>$finish,'total'=>$total,'completed'=>$complete];
        });
    }

    public static function date(string $raw):?string
    {
        if(preg_match('/^[1-9][0-9]{0,9}$/D',$raw)&&(float)$raw<=4102444800)return gmdate('Y-m-d H:i:s',(int)$raw);
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$raw))return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new \DateTimeZone('UTC'));
        return $d!==false&&$d->format('Y-m-d H:i:s')===$raw?$raw:null;
    }
}
