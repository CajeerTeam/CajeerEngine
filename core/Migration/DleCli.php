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
        if(($argv[1]??'')==='migration:activate-user')return self::activate($root,$argv);
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
    private static function activate(string $root,array $argv):int
    {
        if(count($argv)!==4||$argv[3]!=='--yes'||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$argv[2])){fwrite(STDERR,"Usage: php bin/cajeer migration:activate-user USER_UUID --yes < password-file-or-secure-pipe\n");return 2;}
        // Refuse interactive echoing: passwords are provided by an approved secret input/pipe, never arguments.
        if(function_exists('stream_isatty')&&stream_isatty(STDIN)){fwrite(STDERR,"Supply the new password through protected stdin, not a terminal or argument.\n");return 2;}
        $password=fgets(STDIN,1026);if($password===false||strlen($password)>1024||!feof(STDIN)&&fgetc(STDIN)!==false){fwrite(STDERR,"Expected one password line (max 1024 bytes).\n");return 2;}
        $password=rtrim($password,"\r\n");$lease=null;
        try{
            OperationsCli::loadEnv($root);$config=new ConfigRepository($root);$db=new DatabaseManager($config);
            $lease=\CajeerEngine\Update\OperationLock::acquire($root,true);
            if(\CajeerEngine\Update\OperationLock::maintenance($root))throw new \RuntimeException('Maintenance or recovery is active.');
            $roles=new RoleRepository($db);$users=new UserRepository($db,$roles);
            (new IdentityMigration($db,$users,$roles))->activate($argv[2],$password,new \CajeerEngine\Security\PasswordHasher($config));
            echo "Migrated account activated.\n";return 0;
        }catch(\Throwable $e){fwrite(STDERR,($e instanceof \PDOException||$e instanceof \CajeerEngine\Database\DatabaseUnavailable?'Database operation failed.':($e instanceof \InvalidArgumentException||$e instanceof \RuntimeException?$e->getMessage():'Activation failed.')).PHP_EOL);return 1;}
        finally{if(function_exists('sodium_memzero'))sodium_memzero($password);if($lease!==null)\CajeerEngine\Update\OperationLock::release($lease);}
    }
}
