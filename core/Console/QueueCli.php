<?php
declare(strict_types=1);
namespace CajeerEngine\Console;

use CajeerEngine\Queue\{QueueFactory,QueueWorker,ManagedQueueDriverInterface,LeaseQueueDriverInterface,RedisQueueDriver};
use CajeerEngine\Scheduler\SchedulerRunner;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\{WebhookRepository,WebhookDispatcher};
use CajeerEngine\Search\{SearchFactory,SearchIndexer};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};

/** Bounded CLI batches. bin/cajeer holds the existing shared activity lock. */
final class QueueCli
{
    public const COMMANDS=['queue:push','queue:work','queue:failed','queue:retry','queue:recover','queue:diagnostics','queue:redis-migrate','webhook:dispatch','scheduler:run','schedule:run','schedule:due','scheduler:due'];
    public static function run(string $root,array $argv): int
    {
        try {
            OperationsCli::loadEnv($root);$command=$argv[1];$o=[];$args=[];
            for($i=2;$i<count($argv);$i++){
                if(!str_starts_with($argv[$i],'--')){$args[]=$argv[$i];continue;}
                $p=explode('=',substr($argv[$i],2),2);$key=$p[0];if(isset($o[$key]))throw new \InvalidArgumentException('Duplicate option --'.$key);
                if(in_array($key,['json','once','yes'],true)){$o[$key]=true;continue;}
                if(!in_array($key,['queue','limit','payload','id'],true))throw new \InvalidArgumentException('Unknown option --'.$key);
                $v=$p[1]??($argv[++$i]??null);if($v===null||str_starts_with($v,'--'))throw new \InvalidArgumentException('Missing value for --'.$key);$o[$key]=$v;
            }
            if(isset($o['limit'])&&(!ctype_digit($o['limit'])||(int)$o['limit']<1||(int)$o['limit']>1000))throw new \InvalidArgumentException('limit must be 1..1000');
            if($command==='webhook:dispatch'){$result=['ok'=>true,'queued'=>true,'event_id'=>(new WebhookRepository($root))->pushOutbox($args[0]??throw new \InvalidArgumentException('Provide event name'),json_decode((string)($o['payload']??'{}'),true,512,JSON_THROW_ON_ERROR))];echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;return 0;}
            $cfg=new ConfigRepository($root);$db=new DatabaseManager($cfg);$q=(new QueueFactory($root,$cfg,$db))->make();$name=(string)($o['queue']??'default');
            $result=match($command){
                'queue:push'=>['ok'=>true,'id'=>$q->push($args[0]??throw new \InvalidArgumentException('Provide job name'),json_decode((string)($o['payload']??'{}'),true,512,JSON_THROW_ON_ERROR),$name)],
                'queue:failed'=>['ok'=>true,'data'=>$q->failed($name)],
                'queue:retry'=>['ok'=>$q->retry($args[0]??$o['id']??throw new \InvalidArgumentException('Provide job ID'),$name)],
                'queue:recover'=>['ok'=>true,'recovered'=>$q instanceof LeaseQueueDriverInterface?$q->recover($name,(int)($o['limit']??100)):throw new \RuntimeException('Lease recovery unsupported')],
                'queue:diagnostics'=>['ok'=>true,'data'=>$q->diagnostics()],
                'queue:redis-migrate'=>['ok'=>true,'recovered'=>$q instanceof RedisQueueDriver&&isset($o['yes'])?$q->migrateLegacy():throw new \RuntimeException('Stop old workers and producers, then run with --yes on Redis')],
                default=>self::runTasks($root,$cfg,$db,$q,$command,$name,$o),
            };
            echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            return (($result['ok']??true)===false||($result['failed']??0)>0)?1:0;
        }catch(\Throwable $e){fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL);return 1;}
    }
    private static function runTasks(string $root,ConfigRepository $cfg,DatabaseManager $db,ManagedQueueDriverInterface $q,string $command,string $name,array $o): array
    {
        if(is_file($root.'/vendor/autoload.php')){$c=\CajeerEngine\Kernel\Application::boot($root)->container();if($command==='queue:work')return $c->get(QueueWorker::class)->work($name,isset($o['once'])?1:(int)($o['limit']??$cfg->int('queue.workers.default_limit',10)));$scheduler=$c->get(SchedulerRunner::class);}
        else {
            // Same actual services for source installations; extensions use the native runtime.
            $wh=new WebhookRepository($root);$wd=new WebhookDispatcher(new \CajeerEngine\Security\SignedPayload(),$wh);
            $index=new SearchIndexer(new ContentTypeRepository($root,$db),new ContentEntryRepository($root,$db),(new SearchFactory($root,$cfg,$db))->make());
            $runtime=NativeCli::extensionRuntime($root,$cfg);$runtime->boot();$platform=$runtime->platform();
            $worker=new QueueWorker($q,$index,$wh,$wd,$platform);if($command==='queue:work')return $worker->work($name,isset($o['once'])?1:(int)($o['limit']??$cfg->int('queue.workers.default_limit',10)));$scheduler=new SchedulerRunner($root,$index,$wh,$wd,$q,$platform,\CajeerEngine\Scheduler\TaskRunStore::fromConfig($root,$cfg,$db));
        }
        if(str_ends_with($command,':due'))return ['ok'=>true,'data'=>$scheduler->tasks()];
        return $scheduler->run();
    }
}
