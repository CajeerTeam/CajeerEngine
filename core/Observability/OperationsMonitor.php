<?php
declare(strict_types=1);
namespace CajeerEngine\Observability;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Queue\QueueFactory;
use CajeerEngine\Scheduler\TaskRunStore;
use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Update\OperationLock;
use CajeerEngine\Support\JsonFile;

/** Cheap readiness and explicit, sanitized operational diagnostics. */
final readonly class OperationsMonitor
{
    private ConfigRepository $config;
    public function __construct(private string $root,?ConfigRepository $config=null){$this->config=$config??new ConfigRepository($root);}
    public function readiness(): array
    {
        $checks=[];
        $checks['dependencies']=is_file($this->root.'/vendor/autoload.php')&&class_exists(\Symfony\Component\HttpFoundation\Request::class)&&class_exists(\Symfony\Component\Routing\RouteCollection::class)&&extension_loaded('mbstring')&&extension_loaded('sodium')&&extension_loaded('fileinfo')&&extension_loaded('pdo');
        $checks['installed']=is_file($this->root.'/storage/app/installed.lock')||is_file($this->root.'/storage/installed.lock');
        $checks['maintenance']=!OperationLock::maintenance($this->root);
        $checks['storage']=is_writable($this->root.'/storage/app')&&is_writable($this->root.'/storage/logs')&&is_writable($this->root.'/storage/cache');
        $lock=null;try{$lock=OperationLock::acquire($this->root,false);$checks['activity_lock']=true;}catch(\Throwable){$checks['activity_lock']=false;}
        // During recovery never touch the database or partially replaced code.
        $checks['database']=false;
        if($checks['maintenance']&&$checks['activity_lock']){
            try{$this->assertDatabasePresent();$db=new DatabaseManager($this->config);$pdo=$db->connection();$pdo->query('SELECT 1')->fetchColumn();foreach(['ce_content_entries','ce_users','ce_public_routes','ce_cms_search','ce_migrations'] as $table)$pdo->query('SELECT 1 FROM '.$table.' WHERE 1=0');$applied=$pdo->query("SELECT migration FROM ce_migrations WHERE status='applied'")->fetchAll(\PDO::FETCH_COLUMN);foreach(glob($this->root.'/migrations/'.$db->driver().'/*.sql')?:[] as $migration)if(!in_array(basename($migration),$applied,true))throw new \RuntimeException('Pending migration');$checks['database']=true;}catch(\Throwable){}
        }
        OperationLock::release($lock);
        $free=@disk_free_space($this->root.'/storage');$checks['disk']=$free!==false&&$free>=$this->minFree();
        return ['ok'=>!in_array(false,$checks,true),'status'=>in_array(false,$checks,true)?'not_ready':'ready','checks'=>$checks];
    }
    public function report(): array
    {
        $ready=$this->readiness();$checks=[];$alerts=[];
        $probe=function(string $name,callable $fn)use(&$checks,&$alerts){$start=hrtime(true);try{$data=$fn();$ok=(bool)($data['ok']??true);$checks[$name]=['ok'=>$ok,'latency_ms'=>round((hrtime(true)-$start)/1e6,3)]+$data;if(!$ok)$alerts[]=$name.'_unavailable';}catch(\Throwable){$checks[$name]=['ok'=>false,'latency_ms'=>round((hrtime(true)-$start)/1e6,3),'error'=>'probe_failed'];$alerts[]=$name.'_unavailable';}};
        $lock=null;try{$lock=OperationLock::acquire($this->root,false);if(OperationLock::maintenance($this->root))throw new \RuntimeException('Maintenance');}catch(\Throwable){OperationLock::release($lock);return ['ok'=>false,'status'=>'not_ready','time'=>gmdate(DATE_ATOM),'readiness'=>$ready,'checks'=>[],'disk'=>['free_bytes'=>null,'total_bytes'=>null],'alerts'=>['maintenance_or_activity_lock']];}
        try {
        $db=new DatabaseManager($this->config);
        $probe('database',function()use($db){$this->assertDatabasePresent();$h=$db->health();return ['ok'=>(bool)($h['ok']??false)&&(bool)($h['schema_ready']??false),'driver'=>$db->driver(),'schema_ready'=>(bool)($h['schema_ready']??false)];});
        $probe('storage',function(){ $s=new StorageManager($this->root,$this->config);$d=$s->diagnostics();return ['ok'=>(bool)($d['local_probe_write']??false)&&(bool)($d['local_probe_delete']??false)&&(bool)($d['driver_probe']['ok']??false),'driver'=>$s->driver()];});
        $probe('queue',function()use($db){if($this->config->string('queue.default')==='pgsql')$this->assertDatabasePresent();$d=(new QueueFactory($this->root,$this->config,$db))->make()->diagnostics();$counts=array_fill_keys(['pending','reserved','processed','failed'],0);foreach($d['queues']??[] as $q)foreach($counts as $k=>$v)$counts[$k]+=(int)($q[$k]??0);return ['ok'=>($d['writable']??true)&&($d['connected']??true),'driver'=>$d['driver']??'unknown','counts'=>$counts,'oldest_ready_age_seconds'=>(int)($d['oldest_ready_age_seconds']??0)];});
        $probe('scheduler',function()use($db){if($this->config->string('scheduler.driver')==='pgsql')$this->assertDatabasePresent();$store=TaskRunStore::fromConfig($this->root,$this->config,$db);$tasks=[];foreach(['tmp.cleanup'=>3600,'queue.cleanup_processed'=>86400,'search.index_published'=>60,'webhooks.dispatch_pending'=>60] as $name=>$interval){$s=$store->status($name);$last=strtotime((string)($s['last_success_at']??''))?:0;$tasks[$name]=['status'=>$s['status']??'never','last_success'=>$last,'stale'=>$last===0||time()-$last>max(180,$interval*2)];}return ['ok'=>!in_array(true,array_column($tasks,'stale'),true),'tasks'=>$tasks];});
        $probe('cache',fn()=> $this->cache());
        $probe('search',function()use($db){$this->assertDatabasePresent();$d=(new \CajeerEngine\Search\SearchFactory($this->root,$this->config,$db))->make()->diagnostics();return ['ok'=>(bool)($d['ok']??$d['writable']??true),'documents'=>(int)($d['documents']??0)];});
        $probe('backup',fn()=> $this->backup());
        if(TraceExporter::enabled())$probe('traces',function()use($db){$store=TaskRunStore::fromConfig($this->root,$this->config,$db);$state=$store->status('observability.export_traces');$pending=count(glob($this->root.'/storage/logs/observability/traces/span-*.json')?:[]);return ['ok'=>$pending<1000&&($state['status']??'never')!=='failed','pending'=>$pending,'status'=>$state['status']??'never'];});
        $probe('update',function(){ $d=(new JsonFile($this->root.'/storage/logs/observability/update.json'))->readObject();return ['ok'=>($d['status']??'never')!=='failure','last_success'=>(int)($d['last_success']??0),'status'=>$d['status']??'never'];});
        $probe('worker',function(){ $p=$this->root.'/storage/logs/observability/worker.json';$d=(new JsonFile($p))->readObject();$last=(int)($d['timestamp']??0);return ['ok'=>$last>0&&time()-$last<=max(60,$this->config->int('observability.worker_max_age',300)),'last_seen'=>$last,'status'=>$d['status']??'never'];});
        $free=@disk_free_space($this->root.'/storage');$total=@disk_total_space($this->root.'/storage');
        if($free===false||$free<$this->minFree())$alerts[]='disk_low';
        if(($checks['queue']['counts']['failed']??0)>0)$alerts[]='queue_failed_jobs';
        if(($checks['queue']['oldest_ready_age_seconds']??0)>$this->config->int('observability.queue_max_age',300))$alerts[]='queue_backlog_stale';
        try{$http=(new Metrics($this->root))->httpSummary();}catch(\Throwable){$http=['requests'=>null,'errors_5xx'=>null,'average_ms'=>null,'p95_bucket_ms'=>null];$alerts[]='metrics_unavailable';}
        return ['ok'=>$ready['ok'],'status'=>!$ready['ok']?'not_ready':($alerts?'degraded':'ready'),'time'=>gmdate(DATE_ATOM),'readiness'=>$ready,'checks'=>$checks,'http'=>$http,'disk'=>['free_bytes'=>$free===false?null:(int)$free,'total_bytes'=>$total===false?null:(int)$total],'alerts'=>$alerts];
        }finally{OperationLock::release($lock);}
    }
    private function assertDatabasePresent(): void
    {
        if($this->config->string('database.default')!=='sqlite')return;
        $path=$this->config->string('database.connections.sqlite.database');
        if(!str_starts_with($path,'/'))$path=$this->root.'/'.$path;
        if(!is_file($path))throw new \RuntimeException('SQLite database missing');
    }
    private function minFree(): int{return max(0,$this->config->int('observability.min_free_bytes',104857600));}
    private function cache(): array
    {
        $driver=$this->config->string('cache.default','file');if($driver!=='redis')return ['ok'=>in_array($driver,['file','array','none'],true),'driver'=>in_array($driver,['file','array','none'],true)?$driver:'unknown','probe'=>'not_required'];
        if(!class_exists(\Redis::class))throw new \RuntimeException('Redis unavailable');
        $r=new \Redis();try{if(!$r->connect($this->config->string('cache.stores.redis.host','127.0.0.1'),$this->config->int('cache.stores.redis.port',6379),1.0))throw new \RuntimeException('Redis unavailable');$r->setOption(\Redis::OPT_READ_TIMEOUT,1.0);$password=$this->config->string('cache.stores.redis.password');if($password!==''&&!$r->auth($password))throw new \RuntimeException('Redis auth failed');if(!$r->select($this->config->int('cache.stores.redis.database',0)))throw new \RuntimeException('Redis select failed');return ['ok'=>(bool)$r->ping(),'driver'=>'redis'];}finally{try{$r->close();}catch(\Throwable){}}
    }
    private function backup(): array
    {
        // Marker is written only after authenticated full backup verification; no key needed by web process.
        $d=(new JsonFile($this->root.'/storage/logs/observability/backup.json'))->readObject();$last=(int)($d['timestamp']??0);
        return ['ok'=>$last>0&&time()-$last<=max(60,$this->config->int('observability.backup_max_age',86400)),'last_success'=>$last,'age_seconds'=>$last>0?max(0,time()-$last):null];
    }
    public static function marker(string $root,string $name,string $status='success'): void
    {
        if(!in_array($name,['backup','worker','update'],true))return;
        try{$dir=$root.'/storage/logs/observability';\CajeerEngine\Update\OperationFiles::dir($dir,0770);$h=fopen($dir.'/'.$name.'.lock','c+');if(!$h)throw new \RuntimeException('Marker lock unavailable');try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Marker lock failed');$file=new JsonFile($dir.'/'.$name.'.json');$old=$file->readObject();$file->writeObject(['timestamp'=>time(),'last_success'=>$status==='success'?time():($old['last_success']??0),'status'=>$status]);}finally{fclose($h);}}catch(\Throwable){error_log('CajeerEngine: operation marker write failed');}
    }
    public function prometheus(): string
    {
        $r=$this->cachedReport();$out=(new Metrics($this->root))->prometheus();
        $out.="# TYPE cajeer_ready gauge\ncajeer_ready ".(int)$r['ok']."\n# TYPE cajeer_dependency_up gauge\n";
        foreach($r['checks'] as $name=>$d)$out.='cajeer_dependency_up{component="'.$name.'"} '.(int)$d['ok']."\n";
        foreach(['free_bytes','total_bytes'] as $key)if($r['disk'][$key]!==null)$out.="# TYPE cajeer_disk_$key gauge\ncajeer_disk_$key ".$r['disk'][$key]."\n";
        $out.="# TYPE cajeer_queue_jobs gauge\n";foreach($r['checks']['queue']['counts']??[] as $k=>$v)$out.='cajeer_queue_jobs{state="'.$k.'"} '.$v."\n";
        $out.="# TYPE cajeer_queue_oldest_ready_age_seconds gauge\ncajeer_queue_oldest_ready_age_seconds ".(int)($r['checks']['queue']['oldest_ready_age_seconds']??0)."\n";
        $out.="# TYPE cajeer_scheduler_last_success_timestamp_seconds gauge\n";foreach($r['checks']['scheduler']['tasks']??[] as $n=>$t)$out.='cajeer_scheduler_last_success_timestamp_seconds{task="'.$n.'"} '.$t['last_success']."\n";
        foreach(['backup'=>'last_success','worker'=>'last_seen','update'=>'last_success'] as $n=>$k)$out.="# TYPE cajeer_{$n}_{$k}_timestamp_seconds gauge\ncajeer_{$n}_{$k}_timestamp_seconds ".(int)($r['checks'][$n][$k]??0)."\n";
        if(isset($r['checks']['traces']['pending']))$out.="# TYPE cajeer_trace_spool_pending gauge\ncajeer_trace_spool_pending ".$r['checks']['traces']['pending']."\n";
        return $out;
    }
    /** Scrapes reuse a short-lived sample; a concurrent scrape never queues expensive probes. */
    public function cachedReport(): array
    {
        $dir=$this->root.'/storage/logs/observability';\CajeerEngine\Update\OperationFiles::dir($dir,0770);
        $file=new JsonFile($dir.'/report.json');$now=time();
        if(OperationLock::maintenance($this->root))return $this->report();
        try{$lease=OperationLock::acquire($this->root,false);OperationLock::release($lease);}catch(\Throwable){return $this->report();}
        $saved=$file->readObject();if(($saved['sampled_at']??0)>=$now-15)return $saved['report'];
        $h=fopen($dir.'/report.lock','c+');if(!$h)throw new \RuntimeException('Cannot lock diagnostics');
        try{
            if(!flock($h,LOCK_EX|LOCK_NB))throw new \RuntimeException('Diagnostic sample in progress');
            $saved=$file->readObject();if(($saved['sampled_at']??0)>=$now-15)return $saved['report'];
            $report=$this->report();$file->writeObject(['sampled_at'=>time(),'report'=>$report]);return $report;
        }finally{fclose($h);}
    }
}
