<?php

declare(strict_types=1);
namespace CajeerEngine\Media;
use CajeerEngine\Console\OperationsCli;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\OperationLock;
final class MediaOperations
{
    public const COMMANDS=['media:list','media:check','media:migrate','media:variants','media:repair','media:recover','media:usage','media:s3-sync','media:gc'];
    public static function run(string $root,array $argv): int
    {
        $lease=null;
        try{
            OperationsCli::loadEnv($root);$command=$argv[1];$opts=[];
            foreach(array_slice($argv,2) as $v){if(!str_starts_with($v,'--'))throw new \InvalidArgumentException('Use --id=UUID or --json');[$k,$val]=array_pad(explode('=',substr($v,2),2),2,true);if(!in_array($k,['id','json','q','dry-run'],true)||isset($opts[$k]))throw new \InvalidArgumentException('Invalid option');$opts[$k]=$val;}
            $write=in_array($command,['media:migrate','media:variants','media:repair','media:recover','media:s3-sync'],true);
            $lease=OperationLock::acquire($root,$write);
            if(OperationLock::maintenance($root))throw new \RuntimeException('Resolve application maintenance or recovery first');
            $repo=new MediaRepository($root,new StorageManager($root,new ConfigRepository($root)));
            $id=static fn()=>is_string($opts['id']??null)?$opts['id']:throw new \InvalidArgumentException('Provide --id=UUID');
            $result=match($command){
                'media:list'=>['ok'=>true,'data'=>$repo->all((string)($opts['q']??''))],
                'media:check'=>$repo->diagnostics(true),
                'media:migrate'=>$repo->migrate(),
                'media:variants'=>['ok'=>true,'data'=>$repo->regenerate($id())],
                'media:repair'=>$repo->repair(),
                'media:recover'=>$repo->recover(),
                'media:usage'=>['ok'=>true,'data'=>$repo->usage($id())],
                'media:s3-sync'=>['ok'=>true,'data'=>$repo->syncReplica($id())],
                'media:gc'=>['ok'=>true,'dry_run'=>true,'orphans'=>$repo->diagnostics(true)['orphans']],
            };
            echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;return ($result['ok']??false)?0:1;
        }catch(\Throwable $e){fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL);return 1;}finally{OperationLock::release($lease);}
    }
}
