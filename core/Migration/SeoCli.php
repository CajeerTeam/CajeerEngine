<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\Repository\{UserRepository,RoleRepository};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationLock;

/** Operator-only SEO migration, never callable via a public HTTP request. */
final class SeoCli
{
    public static function run(string $root,array $argv):int
    {
        if(count($argv)<4||($argv[3]??'')!=='--yes'||!preg_match('/^[a-f0-9]{32}$/D',$argv[2]??'')){
            fwrite(STDERR,"Usage: php bin/cajeer migration:seo-import PROJECT_ID --yes [--origin=https://old.example.com] [--legacy-map=/secure/urls.json] [--wp-pattern=/%postname%/] [--dle-pattern=/%id%-%slug%.html]\n");return 2;
        }
        $options=['origin'=>'','legacy-map'=>'','wp-pattern'=>'','dle-pattern'=>''];
        foreach(array_slice($argv,4) as $arg){if(!preg_match('/^--(origin|legacy-map|wp-pattern|dle-pattern)=(.*)$/Ds',$arg,$m)||$options[$m[1]]!=='')return 2;$options[$m[1]]=$m[2];}
        $lease=null;
        try{
            OperationsCli::loadEnv($root);
            $lease=OperationLock::acquire($root,true);
            if(OperationLock::active($root)||is_file($root.'/storage/app/backup-restore.json')||is_file($root.'/storage/app/extensions/deployment.json'))throw new \RuntimeException('Recovery or deployment is pending.');
            $config=new ConfigRepository($root);$db=new DatabaseManager($config);$entries=new ContentEntryRepository($root,$db);
            $center=MigrationFactory::create($root,$db,new ContentTypeRepository($root,$db),$entries,new UserRepository($db,new RoleRepository($db)));
            $project=$center->show($argv[2]);if(($project['status']??'')!=='completed')throw new \RuntimeException('Complete content migration first.');
            $wp=str_starts_with((string)$project['source'],'wordpress-');
            if($options[$wp?'dle-pattern':'wp-pattern']!=='')throw new \InvalidArgumentException('Permalink pattern does not match the migration source.');
            $aliases=SeoMigration::parseMap($options['legacy-map']);
            $inventory=$center->seoSourceInventory($argv[2]);
            $report=(new SeoMigration($root,$db,$entries))->execute($project,$inventory,$options['origin'],$aliases,$options[$wp?'wp-pattern':'dle-pattern']);
            echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            return count($report['skipped']??[])===0?0:3;
        }catch(\Throwable $e){
            $message=$e instanceof \PDOException||$e instanceof \CajeerEngine\Database\DatabaseUnavailable?'SEO database operation failed.':($e instanceof \InvalidArgumentException||$e instanceof \RuntimeException||$e instanceof \DomainException?$e->getMessage():'SEO migration failed.');
            fwrite(STDERR,$message.PHP_EOL);return 1;
        }finally{if($lease!==null)OperationLock::release($lease);}
    }
}
