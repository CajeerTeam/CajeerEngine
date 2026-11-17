<?php

declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Media\MediaRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\OperationLock;

/** Media is a distinct resumable post-content stage; never runs in an HTTP request. */
final class MediaCli
{
    public static function run(string $root,array $argv):int
    {
        if(count($argv)<5||count($argv)>7||($argv[4]??'')!=='--yes'||!preg_match('/^[a-f0-9]{32}$/D',$argv[2]??'')||!is_string($argv[3])||$argv[3]===''){
            fwrite(STDERR,"Usage: php bin/cajeer migration:media-import PROJECT_ID /path/to/source/uploads --yes [--origin=https://old.example.com] [--featured-field=cover]\n");return 2;
        }
        $origin='';$featuredField=null;
        foreach(array_slice($argv,5) as $arg){
            if(str_starts_with($arg,'--origin=')&&$origin==='')$origin=substr($arg,9);
            elseif(str_starts_with($arg,'--featured-field=')&&$featuredField===null)$featuredField=substr($arg,17);
            else{fwrite(STDERR,"Invalid media import option.\n");return 2;}
        }
        $lease=null;
        try{
            OperationsCli::loadEnv($root);$origin=MediaMigration::validateOrigin($origin);
            $source=realpath($argv[3]);
            if($source===false||!is_dir($source)||is_link($argv[3]))throw new \InvalidArgumentException('Source uploads directory is missing.');
            foreach([$root.'/public/uploads',$root.'/storage/app/media-private'] as $dest){$dest=realpath($dest);if($dest!==false&&($source===$dest||str_starts_with($source,$dest.DIRECTORY_SEPARATOR)))throw new \InvalidArgumentException('Source uploads must not be the target media library.');}
            $lease=OperationLock::acquire($root,true);
            if(OperationLock::active($root)||is_file($root.'/storage/app/backup-restore.json')||is_file($root.'/storage/app/extensions/deployment.json'))throw new \RuntimeException('Maintenance or recovery pending; finish it before media import.');
            $config=new ConfigRepository($root);$db=new DatabaseManager($config);
            $types=new ContentTypeRepository($root,$db);$entries=new ContentEntryRepository($root,$db);
            $users=new UserRepository($db,new RoleRepository($db));
            $center=MigrationFactory::create($root,$db,$types,$entries,$users);
            $project=$center->show($argv[2]);
            if(($project['status']??'')!=='completed')throw new \RuntimeException('Complete content migration before running media transfer.');
            $media=new MediaRepository($root,new StorageManager($root,$config),$db);
            $metadata=$featuredField!==null?$center->mediaSourceMetadata($argv[2]):[];
            $result=(new MediaMigration($db,$entries,$media,$types))->execute($project,$source,$origin,$metadata,$featuredField);
            echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            return count($result['skipped_files'])||count($result['unsupported_files'])||count($result['unresolved_references'])||count($result['unresolved_featured'])?3:0;
        }catch(\Throwable $e){
            // PDO failures may contain connection strings; never write raw database exceptions to logs.
            $message=$e instanceof \PDOException?'Media database operation failed.':($e instanceof \InvalidArgumentException||$e instanceof \RuntimeException||$e instanceof \DomainException?$e->getMessage():'Media migration failed.');
            fwrite(STDERR,$message.PHP_EOL);return 1;
        }finally{if($lease!==null)OperationLock::release($lease);}
    }
}
