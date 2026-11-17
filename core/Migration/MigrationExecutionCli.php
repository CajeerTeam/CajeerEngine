<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};
use CajeerEngine\Update\OperationLock;

/** Explicit local operator interface; never exposed to HTTP, cron or public routes. */
final class MigrationExecutionCli
{
    public static function run(string $root,array $argv):int
    {
        $command=$argv[1]??'';$lease=null;
        try{
            $id=MigrationExecution::validateId($argv[2]??'');
            OperationsCli::loadEnv($root);
            $db=new DatabaseManager(new ConfigRepository($root));
            $types=new ContentTypeRepository($root,$db);$entries=new ContentEntryRepository($root,$db);
            $users=new UserRepository($db,new RoleRepository($db));
            $planner=new MigrationPlanner($types,$entries,$users,$db);
            $execution=new MigrationExecution($root,$db,$entries,$types,$planner);
            if($command==='migration:status'){
                if(count($argv)!==3)throw new \InvalidArgumentException('Usage: migration:status PROJECT_ID');
                $result=$execution->status($id)??['project_id'=>$id,'state'=>'not_started'];
            }elseif($command==='migration:pause'){
                if(count($argv)!==3)throw new \InvalidArgumentException('Usage: migration:pause PROJECT_ID');
                $execution->requestPause($id);$result=['project_id'=>$id,'pause_requested'=>true];
            }elseif($command==='migration:rollback'){
                if(count($argv)!==5||$argv[4]!=='--yes'||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$argv[3]))throw new \InvalidArgumentException('Usage: migration:rollback PROJECT_ID BACKUP_ID --yes');
                $lease=OperationLock::acquire($root,true);
                $result=$execution->rollback($id,$argv[3]);
            }elseif(in_array($command,['migration:execute','migration:resume'],true)){
                if(count($argv)<5||count($argv)>7||$argv[4]!=='--yes'||!preg_match('/^[a-f0-9]{64}$/D',$argv[3]))throw new \InvalidArgumentException('Usage: migration:execute|migration:resume PROJECT_ID PLAN_SHA256 --yes [--batch=50] [--max-batches=1]');
                $batch=50;$maxBatches=0;$seen=[];
                foreach(array_slice($argv,5) as $arg){
                    if(!preg_match('/^--(batch|max-batches)=([0-9]{1,6})$/D',$arg,$m)||isset($seen[$m[1]]))throw new \InvalidArgumentException('Invalid execution option.');
                    $seen[$m[1]]=true;$v=(int)$m[2];
                    if($m[1]==='batch')$batch=$v;else $maxBatches=$v;
                }
                $lease=OperationLock::acquire($root,true);
                if($command==='migration:resume'&&$execution->status($id)===null)throw new \RuntimeException('Migration was not started; use migration:execute.');
                $center=MigrationFactory::create($root,$db,$types,$entries,$users);
                $result=$execution->run($center,$id,$argv[3],$batch,$maxBatches);
            }else throw new \InvalidArgumentException('Unknown migration execution command.');
            echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            return 0;
        }catch(\Throwable $e){
            $safe=$e instanceof \PDOException||$e instanceof \CajeerEngine\Database\DatabaseUnavailable?'Migration database operation failed; the site remains in maintenance mode if import was started.':(
                $e instanceof \InvalidArgumentException||$e instanceof \RuntimeException||$e instanceof \DomainException?$e->getMessage():'Migration execution failed; inspect protected logs.');
            fwrite(STDERR,$safe.PHP_EOL);return 1;
        }finally{if($lease!==null)OperationLock::release($lease);}
    }
}
