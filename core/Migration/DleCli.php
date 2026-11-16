<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};

final class DleCli
{
    public static function run(string $root,array $argv):int
    {
        $wp=($argv[1]??'')==='migration:wordpress-import';
        $command=$wp?'migration:wordpress-import':'migration:dle-import';
        if(count($argv)!==5||$argv[4]!=='--yes'||!preg_match('/^[a-f0-9]{32}$/D',$argv[2])||!preg_match('/^[a-f0-9]{64}$/D',$argv[3])){
            fwrite(STDERR,"Usage: php bin/cajeer $command PROJECT_ID PLAN_SHA256 --yes\n");return 2;
        }
        try{
            OperationsCli::loadEnv($root);
            $db=new DatabaseManager(new ConfigRepository($root));
            $center=MigrationFactory::create($root,$db,new ContentTypeRepository($root,$db),new ContentEntryRepository($root,$db),new UserRepository($db,new RoleRepository($db)));
            $r=$wp?$center->executeWordPress($argv[2],$argv[3]):$center->executeDle($argv[2],$argv[3]);
            echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            return 0;
        }catch(\Throwable $e){
            // SQL exceptions may contain target content or database credentials; never echo them.
            $msg=$e instanceof \PDOException||$e instanceof \CajeerEngine\Database\DatabaseUnavailable?'Database operation failed; check configuration and logs.':($e instanceof \InvalidArgumentException||$e instanceof \RuntimeException||$e instanceof \DomainException?$e->getMessage():'Content import failed.');
            fwrite(STDERR,$msg.PHP_EOL);return 1;
        }
    }
}
