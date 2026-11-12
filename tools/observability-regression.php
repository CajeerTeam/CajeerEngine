<?php
declare(strict_types=1);
use CajeerEngine\Observability\{Metrics,OperationsMonitor,TraceContext,ProbeEndpoint,TraceExporter,TraceScope};
use CajeerEngine\Runtime\{ConfigRepository,RuntimeLogger};
use CajeerEngine\Queue\{FileQueueDriver,QueueWorker};
use CajeerEngine\Scheduler\TaskRunStore;
use CajeerEngine\Integration\{WebhookRepository,WebhookDispatcher};
use CajeerEngine\Search\{SearchIndexer,FileSearchIndex};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};
use CajeerEngine\Security\SignedPayload;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Support\JsonFile;
$source=dirname(__DIR__);require $source.'/core/Console/NativeCli.php';
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function removeTree(string $dir):void{if(!is_dir($dir))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $e){if($e->isLink()||$e->isFile())unlink($e->getPathname());else rmdir($e->getPathname());}rmdir($dir);}
function copyTree(string $from,string $to):void{if(!is_dir($to))mkdir($to,0770,true);foreach(new DirectoryIterator($from)as $e){if($e->isDot())continue;$p=$to.'/'.$e->getFilename();if($e->isDir())copyTree($e->getPathname(),$p);elseif($e->isFile())copy($e->getPathname(),$p);}}
function http(string $base,string $path,string $method='GET',?string $token=null):array{
    $context=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>15,'header'=>$token===null?'':'Authorization: Bearer '.$token."\r\n"]]);
    $body=file_get_contents($base.$path,false,$context);$headers=$http_response_header??[];
    return [(int)substr($headers[0]??'',9,3),$body===false?'':$body,$headers];
}
$root=sys_get_temp_dir().'/ce-observability-'.bin2hex(random_bytes(8));mkdir($root,0700);$server=null;
try{
    foreach(['config','core']as $dir)copyTree($source.'/'.$dir,$root.'/'.$dir);
    foreach(['public','storage/app','storage/cache','storage/logs','storage/queue','public/uploads']as $dir)if(!is_dir($root.'/'.$dir))mkdir($root.'/'.$dir,0770,true);
    copy($source.'/public/index.php',$root.'/public/index.php');copy($source.'/VERSION',$root.'/VERSION');copy($source.'/release.json',$root.'/release.json');
    putenv('APP_ENV=testing');putenv('CONTENT_STORAGE=file');putenv('CMS_STORAGE=file');putenv('MEDIA_STORAGE=file');putenv('SEARCH_DRIVER=file');putenv('QUEUE_DRIVER=file');putenv('SCHEDULER_DRIVER=file');putenv('STORAGE_DRIVER=local');putenv('CACHE_DRIVER=file');putenv('DB_DRIVER=pgsql');putenv('DB_PORT=1');putenv('DB_CONNECT_TIMEOUT=1');putenv('OBSERVABILITY_METRICS_TOKEN');putenv('OBSERVABILITY_LOG_MAX_BYTES=65536');putenv('OBSERVABILITY_OTLP_ENDPOINT');putenv('OBSERVABILITY_OTLP_TOKEN');
    $m=new Metrics($root);$m->observe('http','api.v1.health','2xx',0.02);$m->observe('http','api.v1.health','2xx',0.2);$row=$m->snapshot()['http|api.v1.health|2xx'];check($row['count']===2&&abs($row['sum']-0.22)<1e-8&&$row['buckets'][0]===0&&$row['buckets'][2]===1&&end($row['buckets'])===2,'histogram count, sum and cumulative buckets');
    $pids=[];for($i=0;$i<6;$i++){$pid=pcntl_fork();if($pid===0){for($j=0;$j<40;$j++)$m->observe('http','api.v1.health','2xx',0.01);exit(0);}if($pid<0)throw new RuntimeException('fork failed');$pids[]=$pid;}foreach($pids as $pid){pcntl_waitpid($pid,$status);if(!pcntl_wifexited($status)||pcntl_wexitstatus($status)!==0)throw new RuntimeException('Child failure');}check($m->snapshot()['http|api.v1.health|2xx']['count']===242,'six concurrent PHP writers without lost counters');
    for($i=0;$i<100;$i++)$m->observe('http','/preview/secret-'.$i,'4xx',0.001);check(isset($m->snapshot()['http|other|4xx'])&&count($m->snapshot())===2,'untrusted route labels collapse into bounded other');
    $trace=TraceContext::start('00-0123456789abcdef0123456789abcdef-0123456789abcdef-01');check($trace->traceId==='0123456789abcdef0123456789abcdef'&&$trace->parentId==='0123456789abcdef'&&$trace->spanId!==$trace->parentId,'W3C parent correlation');$invalid=TraceContext::start('00-'.str_repeat('0',32).'-'.str_repeat('0',16).'-00');check($invalid->parentId===null&&$invalid->traceId!==str_repeat('0',32),'invalid trace context replaced');
    $logger=new RuntimeLogger($root);$logger->info('HTTP request handled',$trace->fields()+['request_id'=>'req-1','route'=>'api.v1.health','password'=>'supersecret','path'=>'/preview/private-token','message'=>'private SQL']);$log=file_get_contents($root.'/storage/logs/runtime.jsonl');check(str_contains($log,'req-1')&&str_contains($log,$trace->traceId)&&!str_contains($log,'supersecret')&&!str_contains($log,'private-token')&&!str_contains($log,'private SQL'),'structured log correlation and sensitive context removal');
    for($i=0;$i<500;$i++)$logger->info('HTTP request handled',['request_id'=>str_repeat('r',100),'route'=>'api.v1.health','status'=>200]);check(is_file($root.'/storage/logs/runtime.jsonl.1')&&filesize($root.'/storage/logs/runtime.jsonl')<=65536,'bounded log rotation');
    $cfg=new ConfigRepository($root);$monitor=new OperationsMonitor($root,$cfg);$r=$monitor->readiness();check(!$r['ok']&&!$r['checks']['database']&&!$r['checks']['installed'],'readiness fails missing installation and database');
    $report=$monitor->report();check($report['checks']['storage']['ok']&&$report['checks']['queue']['ok'],'real local storage round-trip and queue probe');check(!$report['checks']['database']['ok']&&in_array('database_unavailable',$report['alerts'],true),'database outage produces alert');check(!$report['checks']['scheduler']['ok']&&in_array('scheduler_unavailable',$report['alerts'],true),'never-run scheduler detected');check(!$report['checks']['backup']['ok'],'missing backup detected');
    $q=new FileQueueDriver($root);$q->push('log.message',['message'=>'test','_available_at'=>gmdate(DATE_ATOM,time()-600)]);$d=$q->diagnostics();check($d['oldest_ready_age_seconds']>=599,'oldest ready age from actual queue data');$r=$monitor->report();check(in_array('queue_backlog_stale',$r['alerts'],true),'queue age alert');
    $wh=new WebhookRepository($root);$indexer=new SearchIndexer(new ContentTypeRepository($root),new ContentEntryRepository($root),new FileSearchIndex($root));$worker=new QueueWorker($q,$indexer,$wh,new WebhookDispatcher(new SignedPayload(),$wh));check($worker->work(null,1)['processed']===1,'actual worker executes queued task');check($m->snapshot()['job|log.message|success']['count']===1,'worker duration metric recorded');check($monitor->report()['checks']['worker']['ok'],'worker heartbeat observed');
    $q->push('unknown',['_max_attempts'=>1]);check($worker->work(null,1)['failed']===1,'actual worker fails unknown handler');check(isset($m->snapshot()['job|other|failure'])&&in_array('queue_failed_jobs',$monitor->report()['alerts'],true),'failure metric and failed jobs alert');
    $store=new TaskRunStore($root);foreach(['tmp.cleanup'=>3600,'queue.cleanup_processed'=>86400,'search.index_published'=>60,'webhooks.dispatch_pending'=>60]as $name=>$interval)$store->run($name,$interval,fn()=>['ok'=>true]);check($monitor->report()['checks']['scheduler']['ok'],'scheduler success timestamps from real task ledger');check(isset($m->snapshot()['scheduler|search.index_published|success']),'scheduler duration recorded');
    OperationsMonitor::marker($root,'backup');check($monitor->report()['checks']['backup']['ok'],'verified backup marker clears age alert');$old=time()-90000;(new JsonFile($root.'/storage/logs/observability/backup.json'))->writeObject(['timestamp'=>$old,'last_success'=>$old,'status'=>'success']);check(!$monitor->report()['checks']['backup']['ok'],'old backup detected');
    $cfg->set('observability.min_free_bytes',PHP_INT_MAX);check(!$monitor->readiness()['checks']['disk']&&in_array('disk_low',$monitor->report()['alerts'],true),'disk threshold failure');$cfg->set('observability.min_free_bytes',0);
    $lock=OperationLock::acquire($root,true);try{check(!$monitor->readiness()['checks']['activity_lock'],'exclusive operation blocks readiness');check($monitor->report()['checks']===[],'diagnostics do not touch dependencies during exclusive operation');}finally{OperationLock::release($lock);}
    // Actual HTTP endpoint tests, using the real front controller and no vendor.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('No socket');$address=stream_socket_get_name($socket,false);fclose($socket);
    $token=bin2hex(random_bytes(32));file_put_contents($root.'/.env','OBSERVABILITY_METRICS_TOKEN='.$token."\n");
    $server=proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public'],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,$root);if(!is_resource($server))throw new RuntimeException('Server start failed');fclose($pipes[0]);$base='http://'.$address;
    for($i=0;$i<100;$i++){if(@file_get_contents($base.'/health/live')!==false)break;usleep(30000);}
    check(http($base,'/health/live')[0]===200,'HTTP liveness without Composer or DB');check(http($base,'/health/live','HEAD')[1]==='','HEAD liveness has no body');check(http($base,'/health/live','POST')[0]===405,'probe rejects unsupported method');check(http($base,'/health/ready')[0]===503,'HTTP readiness rejects unavailable CMS');check(http($base,'/metrics')[0]===401&&http($base,'/metrics','GET','wrong')[0]===401,'metrics requires dedicated token');
    $res=http($base,'/metrics','GET',$token);check($res[0]===200&&str_contains($res[1],'cajeer_http_duration_seconds_count')&&str_contains($res[1],'cajeer_ready 0'),'authenticated real Prometheus endpoint');check(!str_contains($res[1],$token)&&!str_contains($res[1],'/preview/secret'),'metrics excludes credentials and raw routes');
    check(http($base,'/anything')[0]===500,'real bootstrap failure without Composer');$res=http($base,'/metrics','GET',$token);check(str_contains($res[1],'operation="bootstrap",outcome="5xx"'),'bootstrap 5xx counted');
    file_put_contents($root.'/storage/app/maintenance.json','{}');check(http($base,'/health/live')[0]===200&&http($base,'/health/ready')[0]===503,'liveness and readiness split during maintenance');check(http($base,'/metrics','GET',$token)[0]===200,'metrics available during maintenance');unlink($root.'/storage/app/maintenance.json');
    file_put_contents($root.'/.env',"OBSERVABILITY_METRICS_TOKEN=\n");check(http($base,'/metrics')[0]===404,'metrics disabled without configured token');
    // Optional real OTLP/HTTP receiver for verifying wire format and retry retention.
    file_put_contents($root.'/public/otlp-test.php', '<?php $body=file_get_contents("php://input"); file_put_contents(dirname(__DIR__)."/otlp-received.json",$body); header("Content-Type: application/json"); if(is_file(dirname(__DIR__)."/reject-traces")){http_response_code(503);echo "{}";}else echo "{}";');
    putenv('OBSERVABILITY_OTLP_ENDPOINT='.$base.'/otlp-test.php');putenv('OBSERVABILITY_TRACE_SAMPLE_RATE=1');
    $parent=TraceContext::start();$before=TraceScope::enter($parent);$child=TraceScope::child();TraceScope::leave($before);check($child->traceId===$parent->traceId&&$child->parentId===$parent->spanId,'child span shares parent trace');
    $export=new TraceExporter($root);$export->record($parent,'http','api.v1.health',microtime(true),0.01,true);$export->record($child,'database','transaction',microtime(true),0.001,true);
    touch($root.'/reject-traces');check(!$export->flush()['ok']&&count(glob($root.'/storage/logs/observability/traces/span-*.json'))===2,'OTLP failed delivery retains spool');unlink($root.'/reject-traces');
    $sent=$export->flush();check($sent['ok']&&$sent['sent']===2&&count(glob($root.'/storage/logs/observability/traces/span-*.json'))===0,'OTLP real HTTP successful delivery removes spool');
    $payload=json_decode(file_get_contents($root.'/otlp-received.json'),true,512,JSON_THROW_ON_ERROR);$spans=$payload['resourceSpans'][0]['scopeSpans'][0]['spans'];check(count($spans)===2&&count(array_filter($spans,fn($s)=>($s['parentSpanId']??null)===$parent->spanId))===1,'OTLP JSON trace and parent IDs preserved');
    putenv('OBSERVABILITY_OTLP_ENDPOINT');
    $m->buffered('database','query','success',0.005);$m->buffered('database','query','success',0.006);$m->flush();check($m->snapshot()['database|query|success']['count']===2,'buffered query counters flush in one aggregate');
    if(in_array('--database',$argv,true)){
        if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite required for --database');
        putenv('DB_DRIVER=sqlite');putenv('SQLITE_DATABASE='.$root.'/test.sqlite');$db=new CajeerEngine\Database\DatabaseManager(new ConfigRepository($root));$pdo=$db->connection();
        $pdo->exec('CREATE TABLE example(id INTEGER PRIMARY KEY, value TEXT)');$s=$pdo->prepare('INSERT INTO example(value) VALUES (?)');$s->execute(['private-value']);check($pdo->query('SELECT value FROM example')->fetchColumn()==='private-value','real measured PDO exec, prepare and query');
        try{$pdo->query('SELECT * FROM missing_table');throw new RuntimeException('Expected query error');}catch(PDOException){}
        $db->transaction(fn($p)=>$p->exec("UPDATE example SET value='changed'"));$db->disconnect();$m->flush();$rows=$m->snapshot();check($rows['database|query|success']['count']>=6&&$rows['database|query|failure']['count']>=1&&$rows['database|transaction|success']['count']>=1,'actual PDO query/transaction counters');check(!str_contains($m->prometheus(),'private-value')&&!str_contains($m->prometheus(),'missing_table'),'SQL statements and bindings absent from metrics');
    }
    echo "Observability regression complete\n";
}finally{if(is_resource($server)){proc_terminate($server);proc_close($server);}removeTree($root);}
