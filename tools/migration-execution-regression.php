<?php
declare(strict_types=1);
use CajeerEngine\Migration\{MigrationExecution,ContentMigrationImporter,MigrationPlanner,PlanningSource};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository,RevisionConflict};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Backup\RecoveryBundle;

$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function ok(bool $v,string $why):void {if(!$v)throw new RuntimeException($why);echo "PASS $why\n";}
function deny(callable $fn,string $why):void {try{$fn();}catch(Throwable $e){ok($e instanceof RevisionConflict||$e instanceof LogicException,$why);return;}throw new RuntimeException('Expected denied: '.$why);}
try{MigrationExecution::validateId('not-id');throw new RuntimeException('Invalid project accepted');}catch(InvalidArgumentException){echo "PASS invalid project rejected\n";}
$rows=[];for($i=1;$i<=501;$i++)$rows[]=PlanningSource::record('post',['id'=>$i,'title'=>'Article '.$i,'slug'=>'article-'.$i]);
$inventory=PlanningSource::inventory($rows,[]);ok(count($inventory['records'])===501,'more than 500 records accepted without truncation');
try{PlanningSource::inventory(array_fill(0,5001,$rows[0]),[]);throw new RuntimeException('Planning limit bypass');}catch(RuntimeException $e){if($e->getMessage()==='Planning limit bypass')throw $e;echo "PASS over-limit inventory rejected\n";}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)||!class_exists(DOMDocument::class)){
    if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite and DOM required for checkpoint integration');
    echo "SKIP PHP batch SQL integration: PDO SQLite/DOM unavailable\n";exit(0);
}
$work=sys_get_temp_dir().'/ce-checkpoint-'.bin2hex(random_bytes(8));mkdir($work,0700);mkdir($work.'/config');foreach(glob($root.'/config/*.php') as $f)copy($f,$work.'/config/'.basename($f));
putenv('CONTENT_STORAGE=database');putenv('CMS_STORAGE=database');putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$work.'/test.sqlite');
$lease=null;
try{
    $db=new DatabaseManager(new ConfigRepository($work));(new MigrationRunner($db->connection(),$root.'/migrations'))->run('sqlite');
    $types=new ContentTypeRepository($work,$db);$entries=new ContentEntryRepository($work,$db);$users=new UserRepository($db,new RoleRepository($db));
    $types->create(['handle'=>'articles','name'=>'Articles','fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext','required'=>true]]]);
    $records=[];for($i=1;$i<=3;$i++){$r=PlanningSource::record('post',['id'=>$i,'title'=>'Article '.$i,'slug'=>'article-'.$i,'body'=>'Body '.$i,'date'=>'2020-01-01 12:00:00','status'=>'publish']);$r['origin']='dle';$records[]=$r;}
    $inv=PlanningSource::inventory($records,[]);$mapping=['types'=>['post'=>['target'=>'articles','fields'=>['data:body'=>['from'=>'body']]]],'statuses'=>['publish'=>'published'],'users'=>[],'locale'=>'ru','conflict'=>'fail'];
    $planner=new MigrationPlanner($types,$entries,$users,$db);$plan=$planner->plan($inv,$mapping);ok($plan['approvable']&&count($plan['records'])===3,'approved full source plan');
    $id=str_repeat('a',32);$project=['id'=>$id,'source'=>'dle-db','status'=>'approved','mapping'=>$mapping,'plan'=>$plan+['approved_at'=>gmdate(DATE_ATOM)]];
    $db->connection()->prepare('INSERT INTO ce_migration_execution(project_id,source,plan_sha256,source_sha256,backup_id,total,cursor_index,state,started_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,'dle-db',$plan['sha256'],$plan['source_sha256'],'verified-fixture-backup',3,0,'running','2026-11-17','2026-11-17']);
    $lease=OperationLock::acquire($work,true);
    $writer=new ContentMigrationImporter($work,$db,$types,$entries,$planner);
    $one=$writer->executeBatch($project,$inv,0,1);ok($one['cursor']===1&&!$one['completed']&&count($entries->list('articles'))===1,'first batch creates real content and commits cursor');
    deny(fn()=>$writer->executeBatch($project,$inv,0,1),'stale batch cannot repeat or duplicate');
    $bad=$project;$bad['plan']['records'][1]['payload_sha256']=str_repeat('0',64);
    deny(fn()=>$writer->executeBatch($bad,$inv,1,1),'tampered payload fails without committing next checkpoint');
    $pos=$db->connection()->query('SELECT cursor_index FROM ce_migration_execution')->fetchColumn();
    ok((int)$pos===1&&count($entries->list('articles'))===1,'failed batch rolls back content and cursor');
    $two=$writer->executeBatch($project,$inv,1,1);ok($two['cursor']===2,'resume begins at committed checkpoint');
    $three=$writer->executeBatch($project,$inv,2,1);
    $receipt=$writer->receipt($id,'dle-db');
    ok($three['completed']&&$receipt['counts']['create']===3&&count($receipt['records'])===3,'last batch commits compatible full content receipt');
    ok((int)$db->connection()->query('SELECT COUNT(*) FROM ce_migration_execution_items')->fetchColumn()===3,'durable per-record journal has exactly three unique entries');
}finally{if($lease!==null)OperationLock::release($lease);RecoveryBundle::cleanup($work);}
