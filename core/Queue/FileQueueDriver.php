<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

use CajeerEngine\Support\JsonFile;
use CajeerEngine\Update\OperationFiles as F;

/** Single-host durable queue. A stable flock protects every read/modify/write transition. */
final readonly class FileQueueDriver implements LeaseQueueDriverInterface
{
    public function __construct(private string $rootPath, private int $leaseSeconds = 300) {}

    public function push(string $job, array $payload = [], ?string $queue = null): string
    {
        $q=QueueOptions::queue($queue); $id=QueueOptions::id($q,$job,$payload); $available=QueueOptions::available($payload);
        return $this->locked($q,function() use($q,$id,$job,$payload,$available) {
            foreach(['pending','processed','failed'] as $bucket) if(is_file($p=$this->path($bucket,$q,$id))) { $old=$this->read($p);if(QueueOptions::fingerprint($old['name'],$old['payload'])!==QueueOptions::fingerprint($job,$payload))throw new \RuntimeException('Idempotency key reused with different job');return $id; }
            $now=gmdate(DATE_ATOM);
            $this->write($this->path('pending',$q,$id),['id'=>$id,'name'=>$job,'payload'=>$payload,'queue'=>$q,'status'=>'pending','attempts'=>0,'max_attempts'=>QueueOptions::maxAttempts($payload),'available_at'=>gmdate(DATE_ATOM,$available),'reserved_at'=>null,'reservation_token'=>null,'lease_until'=>null,'created_at'=>$now,'updated_at'=>$now,'last_error'=>null]);
            return $id;
        });
    }

    public function pop(?string $queue = null): ?QueuedJob
    {
        $q=QueueOptions::queue($queue);
        return $this->locked($q,function() use($q) {
            $this->recoverLocked($q,100);
            $candidate=null; $candidatePath=null;
            foreach($this->files('pending',$q) as $path) {
                $d=$this->read($path);
                if($d['status']!=='pending'||strtotime($d['available_at'])>time())continue;
                if($candidate===null||[$d['available_at'],$d['created_at'],$d['id']]<[$candidate['available_at'],$candidate['created_at'],$candidate['id']]){$candidate=$d;$candidatePath=$path;}
            }
            if($candidate===null)return null;
            $d=$candidate; $d['status']='reserved'; $d['attempts']=(int)$d['attempts']+1;
            $d['reserved_at']=$d['updated_at']=gmdate(DATE_ATOM); $d['reservation_token']=QueueOptions::token(); $d['lease_until']=time()+QueueOptions::lease($this->leaseSeconds);
            $this->write($candidatePath,$d);
            return new QueuedJob($d['id'],$d['name'],$d['payload'],$q,$d['attempts'],(int)$d['max_attempts'],$d['available_at'],$d['reserved_at'],$candidatePath,$d['reservation_token'],$d['lease_until']);
        });
    }

    public function heartbeat(QueuedJob $job): bool
    {
        return $this->locked($job->queue,function() use($job) {
            $path=$this->path('pending',$job->queue,$job->id); $d=$this->owned($path,$job); if($d===null)return false;
            $d['lease_until']=time()+QueueOptions::lease($this->leaseSeconds); $d['updated_at']=gmdate(DATE_ATOM);$this->write($path,$d);return true;
        });
    }
    public function complete(QueuedJob $job): void { $this->finish($job,null,0); }
    public function fail(QueuedJob $job,string $error,int $backoffSeconds=60): void { $this->finish($job,$error,$backoffSeconds); }
    private function finish(QueuedJob $job,?string $error,int $backoff): void
    {
        $this->locked($job->queue,function() use($job,$error,$backoff) {
            $path=$this->path('pending',$job->queue,$job->id);$d=$this->owned($path,$job);
            if($d===null)throw new \RuntimeException('Queue reservation lost');
            $this->transition($path,$d,$error,$backoff);
        });
    }
    private function owned(string $path,QueuedJob $job): ?array
    {
        if(!is_file($path)||$job->reservationToken===null)return null;
        $d=$this->read($path);
        return $d['status']==='reserved'&&($d['reservation_token']??null)===$job->reservationToken&&($d['lease_until']??0)>time()?$d:null;
    }
    private function transition(string $path,array $d,?string $error,int $backoff): void
    {
        $d['updated_at']=gmdate(DATE_ATOM);$d['reserved_at']=$d['reservation_token']=$d['lease_until']=null;
        if($error===null){$d['status']='processed';$d['processed_at']=$d['updated_at'];}
        else {$d['last_error']=QueueOptions::error($error);if($d['attempts']<$d['max_attempts']){$d['status']='pending';$d['available_at']=gmdate(DATE_ATOM,time()+max(1,$backoff));}else{$d['status']='failed';$d['failed_at']=$d['updated_at'];}}
        // Write state first. If killed before rename, recover() finishes the move; never copy then unlink.
        $this->write($path,$d);
        if($d['status']!=='pending')$this->move($path,$this->path($d['status'],$d['queue'],$d['id']));
    }
    public function recover(?string $queue=null,int $limit=100): int { $q=QueueOptions::queue($queue);return $this->locked($q,fn()=> $this->recoverLocked($q,$limit)); }
    private function recoverLocked(string $q,int $limit): int
    {
        $n=0;foreach($this->files('pending',$q) as $path){if($n>=max(1,$limit))break;$d=$this->read($path);
            // 1.2.1 copied the terminal record before unlinking the reservation. Reconcile only matching records.
            $duplicate=false;foreach(['processed','failed']as $bucket){$target=$this->path($bucket,$q,$d['id']);if(!is_file($target))continue;$terminal=$this->read($target);if(QueueOptions::fingerprint($terminal['name'],$terminal['payload'])!==QueueOptions::fingerprint($d['name'],$d['payload']))throw new \RuntimeException('Conflicting legacy queue records');
                if($d['status']==='pending'&&$bucket==='failed'&&strcmp($d['updated_at']??'', $terminal['updated_at']??'')>=0){if(!unlink($target))throw new \RuntimeException('Cannot reconcile legacy retry');F::syncDir(dirname($target));$n++;continue;}
                if(in_array($d['status'],['reserved',$bucket],true)&&$terminal['status']===$bucket){if(!unlink($path))throw new \RuntimeException('Cannot reconcile legacy reservation');F::syncDir(dirname($path));$n++;$duplicate=true;break;}
                throw new \RuntimeException('Conflicting queue state; inspect '.$d['id']);
            }if($duplicate)continue;
            if(in_array($d['status'],['failed','processed'],true)){$this->move($path,$this->path($d['status'],$q,$d['id']));$n++;}
            elseif($d['status']==='reserved'&&($d['lease_until']??((strtotime($d['reserved_at']??'1970-01-01')?:0)+QueueOptions::lease($this->leaseSeconds)))<=time()){$this->transition($path,$d,'Reservation expired',1);$n++;}
        }
        // A retry may have persisted pending state before its atomic move.
        foreach($this->files('failed',$q) as $path){if($n>=max(1,$limit))break;$d=$this->read($path);if($d['status']==='pending'){$this->move($path,$this->path('pending',$q,$d['id']));$n++;}}
        return $n;
    }
    public function retry(string $id,?string $queue=null): bool
    {
        $q=QueueOptions::queue($queue);return $this->locked($q,function()use($q,$id){$this->recoverLocked($q,100);$path=$this->path('failed',$q,$id);if(!is_file($path))return false;$d=$this->read($path);if($d['status']!=='failed')return false;$d['status']='pending';$d['attempts']=0;$d['available_at']=$d['updated_at']=gmdate(DATE_ATOM);$d['failed_at']=$d['last_error']=$d['reserved_at']=$d['reservation_token']=$d['lease_until']=null;$this->write($path,$d);$this->move($path,$this->path('pending',$q,$id));return true;});
    }
    public function failed(?string $queue=null): array { $q=QueueOptions::queue($queue);return $this->locked($q,function()use($q){$this->recoverLocked($q,100);return array_values(array_filter(array_map($this->read(...),$this->files('failed',$q)),fn($d)=>$d['status']==='failed'));}); }
    public function diagnostics(): array
    {
        $queues=[];foreach($this->names() as $q)$queues[$q]=$this->locked($q,function()use($q){$counts=['pending'=>0,'reserved'=>0,'processed'=>0,'failed'=>0];foreach(['pending','processed','failed'] as $bucket)foreach($this->files($bucket,$q)as $path){$d=$this->read($path);$counts[$d['status']]=($counts[$d['status']]??0)+1;}return $counts;});
        return ['driver'=>'file','root'=>$this->rootPath.'/storage/queue','writable'=>is_writable($this->rootPath.'/storage/queue')||(!is_dir($this->rootPath.'/storage/queue')&&is_writable($this->rootPath.'/storage')),'lease_seconds'=>QueueOptions::lease($this->leaseSeconds),'queues'=>$queues];
    }
    public function cleanupProcessed(int $olderThanSeconds=604800): int
    {
        $n=0;foreach($this->names()as $q)$n+=$this->locked($q,function()use($q,$olderThanSeconds){$n=0;foreach($this->files('processed',$q)as $path)if(!is_file($this->path('pending',$q,basename($path,'.json')))&&strtotime($this->read($path)['processed_at'])<time()-max(1,$olderThanSeconds)&&unlink($path))$n++;return $n;});return $n;
    }
    private function names(): array { $names=[];foreach(['pending','processed','failed']as $b)foreach(glob($this->rootPath.'/storage/queue/'.$b.'/*',GLOB_ONLYDIR)?:[]as $dir)$names[]=QueueOptions::queue(basename($dir));return array_values(array_unique($names)); }
    private function locked(string $q,callable $f): mixed { $q=QueueOptions::queue($q);$dir=F::path($this->rootPath,'storage/queue/locks');F::dir($dir,0770);$p=F::path($this->rootPath,'storage/queue/locks/'.$q.'.lock');$h=fopen($p,'c+');if(!$h)throw new \RuntimeException('Cannot open queue lock');try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock queue');return $f();}finally{fclose($h);} }
    private function dir(string $b,string $q): string { $p=F::path($this->rootPath,'storage/queue/'.$b.'/'.QueueOptions::queue($q));F::dir($p,0770);return $p; }
    private function path(string $b,string $q,string $id): string { QueueOptions::validateId($id);return $this->dir($b,$q).'/'.$id.'.json'; }
    private function files(string $b,string $q): array { return glob($this->dir($b,$q).'/*.json')?:[]; }
    private function read(string $p): array { $d=(new JsonFile($p))->readObject();if(!isset($d['id'],$d['status'],$d['name'],$d['queue'],$d['attempts'],$d['max_attempts'],$d['available_at'],$d['created_at'],$d['payload']))throw new \RuntimeException('Invalid queue record: '.$p);return $d; }
    private function write(string $p,array $d): void { (new JsonFile($p))->writeObject($d); }
    private function move(string $from,string $to): void { if(is_file($to)||is_link($to)||!rename($from,$to))throw new \RuntimeException('Cannot move queue record');F::syncDir(dirname($from));F::syncDir(dirname($to)); }
}
