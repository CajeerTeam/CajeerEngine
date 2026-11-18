<?php

declare(strict_types=1);

namespace CajeerEngine\Migration;

use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{RoleRepository,UserRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationLock;

/** Long-running source inspection and destructive cutover are intentionally CLI-only. */
final class MigrationCutoverCli
{
    public static function run(string $root,array $argv):int
    {
        $lease=null;
        try{
            $command=$argv[1]??'';$project=MigrationExecution::validateId($argv[2]??'');
            $readOnly=$command==='migration:validate';
            if($readOnly&&count($argv)!==3)throw new \InvalidArgumentException('Usage: migration:validate PROJECT_ID');
            OperationsCli::loadEnv($root);
            $db=new DatabaseManager(new ConfigRepository($root));
            $types=new ContentTypeRepository($root,$db);$entries=new ContentEntryRepository($root,$db);
            $users=new UserRepository($db,new RoleRepository($db));
            $center=MigrationFactory::create($root,$db,$types,$entries,$users);
            $service=new MigrationCutover($root,$db,$entries,$types);
            $lease=OperationLock::acquire($root,true);
            $result=match($command){
                'migration:validate'=>$service->validate($center,$project),
                'migration:sync'=>self::sync($service,$center,$project,$argv),
                'migration:cutover'=>self::cutover($service,$center,$project,$argv),
                'migration:sync-rollback'=>self::rollback($service,$project,$argv),
                default=>throw new \InvalidArgumentException('Unknown migration validation/cutover command.'),
            };
            echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
            return 0;
        }catch(\Throwable $e){
            $safe=$e instanceof \PDOException||$e instanceof \CajeerEngine\Database\DatabaseUnavailable?'Migration SQL operation failed. Verify maintenance and backup status.':(
                $e instanceof \RuntimeException||$e instanceof \InvalidArgumentException||$e instanceof \DomainException?$e->getMessage():'Migration cutover failed; review protected logs.');
            fwrite(STDERR,$safe.PHP_EOL);return 1;
        }finally{if($lease!==null)OperationLock::release($lease);}
    }
    private static function sync(MigrationCutover $svc,MigrationCenter $center,string $id,array $argv):array
    {
        if(count($argv)<5||count($argv)>7||$argv[4]!=='--yes'||!preg_match('/^[a-f0-9]{64}$/D',$argv[3]))throw new \InvalidArgumentException('Usage: migration:sync PROJECT_ID VALIDATION_SHA --yes [--batch=50] [--max-batches=1]');
        $batch=50;$max=0;$used=[];
        foreach(array_slice($argv,5) as $option){
            if(!preg_match('/^--(batch|max-batches)=([0-9]{1,6})$/D',$option,$m)||isset($used[$m[1]]))throw new \InvalidArgumentException('Invalid sync option.');
            $used[$m[1]]=true;if($m[1]==='batch')$batch=(int)$m[2];else $max=(int)$m[2];
        }
        return $svc->synchronize($center,$id,$argv[3],$batch,$max);
    }
    private static function cutover(MigrationCutover $svc,MigrationCenter $center,string $id,array $argv):array
    {
        if(count($argv)!==6||$argv[4]!=='--yes'||!str_starts_with($argv[5],'--target='))throw new \InvalidArgumentException('Usage: migration:cutover PROJECT_ID VALIDATION_SHA --yes --target=https://new.example.com');
        return $svc->cutover($center,$id,$argv[3],substr($argv[5],9));
    }
    private static function rollback(MigrationCutover $svc,string $id,array $argv):array
    {
        if(count($argv)!==5||$argv[4]!=='--yes'||!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$argv[3]))throw new \InvalidArgumentException('Usage: migration:sync-rollback PROJECT_ID BACKUP_ID --yes');
        return $svc->rollbackSync($id,$argv[3]);
    }
}
