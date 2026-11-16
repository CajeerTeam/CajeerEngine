<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\RouteIndex;

/** A single bounded DLE batch. Content, projections and receipt commit together. */
final readonly class DleImporter
{
    public function __construct(private string $root,private DatabaseManager $db,private ContentTypeRepository $types,private ContentEntryRepository $entries,private MigrationPlanner $planner){}

    public function receipt(string $project):?array
    {
        if($this->entries->storage()!=='database')return null;
        // Discovery continues to work before the new migration is applied.
        if(!$this->db->tableExists('ce_dle_migration_runs'))return null;
        $s=$this->db->connection()->prepare('SELECT result FROM ce_dle_migration_runs WHERE project_id=:id');$s->execute(['id'=>$project]);$r=$s->fetchColumn();
        return is_string($r)?json_decode($r,true,64,JSON_THROW_ON_ERROR):null;
    }
    public function execute(array $project,array $inventory):array
    {
        if($project['source']!=='dle-db')throw new \InvalidArgumentException('Only DLE database projects can be executed in 1.4.3.');
        if($this->entries->storage()!=='database'||(new \CajeerEngine\Cms\ThemeConfigRepository($this->root,$this->db))->storage()!=='database')throw new \RuntimeException('DLE import requires database-backed content and CMS storage.');
        if(!$this->db->tableExists('ce_dle_migration_runs'))throw new \RuntimeException('Run database migrations before DLE import.');
        $plan=$project['plan']??[];
        if($project['status']!=='approved'||empty($plan['approved_at'])||empty($plan['approvable']))throw new \InvalidArgumentException('An approved migration plan is required.');
        $id=$project['id'];
        if($prior=$this->receipt($id)){if(!hash_equals($prior['plan_sha256'],$plan['sha256']))throw new RevisionConflict('Project already executed with a different plan.');return $prior;}
        if(!class_exists(\DOMDocument::class))throw new \RuntimeException('PHP DOM is required for safe DLE HTML import.');
        return (new RouteIndex($this->root,$this->db))->mutate(function()use($project,$inventory,$plan,$id){
            if($prior=$this->receipt($id)){if(!hash_equals($prior['plan_sha256'],$plan['sha256']))throw new RevisionConflict('Project already executed with a different plan.');return $prior;}
            // RouteIndex owns the target SQL writer lock and transaction, including projection rebuild.
            $fresh=$this->planner->plan($inventory,$project['mapping']);
            if(!hash_equals($plan['sha256'],$fresh['sha256']))throw new RevisionConflict('Source or target changed; run Dry Run and approve again.');
            $records=[];foreach($inventory['records'] as $r)$records[$r['type'].':'.$r['id']]=$r;
            $result=['project_id'=>$id,'plan_sha256'=>$plan['sha256'],'completed_at'=>gmdate(DATE_ATOM),'counts'=>['create'=>0,'update'=>0,'skip'=>0],'records'=>[],'warnings'=>['New user accounts, media files, comments, standalone taxonomies and redirects are not imported by this release. DLE source timestamps are interpreted as UTC.']];
            foreach($fresh['records'] as $a){
                $key=$a['source_type'].':'.$a['source_id'];$r=$records[$key]??throw new \LogicException('Missing source record.');
                if(!in_array($a['source_type'],['post','page'],true))throw new \InvalidArgumentException('Unexpected DLE content type.');
                $row=['source_type'=>$a['source_type'],'source_id'=>$a['source_id'],'target_type'=>$a['target_type'],'target_id'=>$a['target_id'],'action'=>$a['action'],'slug'=>$a['slug'],'source_author'=>$r['author'],'source_date'=>$r['fields']['date']];
                if($a['action']!=='skip'){
                    if(!in_array($a['action'],['create','update'],true))throw new \LogicException('Unresolved migration action.');
                    $rule=$project['mapping']['types'][$r['type']];$type=$this->types->find($rule['target'])??throw new RevisionConflict('Target type disappeared.');
                    $status=$project['mapping']['statuses'][$r['status']]??'draft';
                    $payload=MigrationPlanner::payload($r,$rule,$type,$project['mapping']['locale'],$status);$payload['slug']=$a['slug'];
                    if(!hash_equals($a['payload_sha256'],MigrationPlanner::fingerprint($payload)))throw new RevisionConflict('Payload differs from approved plan.');
                    $date=self::date($r['fields']['date']);
                    $entry=$this->entries->importDleEntry($rule['target'],$payload,$a['action']==='update'?$a['target_id']:null,$a['author_id'],$date);
                    $row['target_id']=$entry['id'];$row['slug']=$entry['slug'];$row['author_id']=$a['author_id'];$row['revision']=$entry['revision'];
                    if($r['fields']['date']!==''&&$date===null)$result['warnings'][]='Invalid historical date for '.$key.'; target timestamp retained.';
                }
                $result['counts'][$a['action']]++;$result['records'][]=$row;
            }
            $q=$this->db->connection()->prepare('INSERT INTO ce_dle_migration_runs(project_id,plan_sha256,completed_at,result) VALUES(:id,:sha,:date,:result)');
            $q->execute(['id'=>$id,'sha'=>$plan['sha256'],'date'=>$result['completed_at'],'result'=>json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            return $result;
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
