<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

/** Atomic Redis transitions. Standalone Redis, durable persistence required. */
final readonly class RedisQueueDriver implements LeaseQueueDriverInterface
{
    public function __construct(private \Redis $redis,private string $prefix='ce:queue:',private int $leaseSeconds=300) {}
    private const SCRIPT=<<<'LUA'
local prefix,q,op,id,token,lease,arg,limit=ARGV[1],ARGV[2],ARGV[3],ARGV[4],ARGV[5],tonumber(ARGV[6]),ARGV[7],tonumber(ARGV[8])
local now=tonumber(redis.call('TIME')[1]); local stamp=ARGV[9]
local ready=prefix..'ready:'..q; local reserved=prefix..'reserved:'..q; local done=prefix..'processed:'..q; local failed=prefix..'failed:'..q
local function read(i) local x=redis.call('HGET',prefix..'job:'..i,'data'); if x then return cjson.decode(x) end end
local function write(d) redis.call('HSET',prefix..'job:'..d.id,'data',cjson.encode(d)); redis.call('SADD',prefix..'queues',d.queue) end
local function finish(d,err,delay)
 redis.call('ZREM',reserved,d.id); d.reservation_token=cjson.null; d.lease_until=cjson.null; d.reserved_at=cjson.null; d.updated_at=stamp
 if err=='' then d.status='processed'; d.processed_at=stamp; redis.call('ZADD',done,now,d.id)
 else d.last_error=err; if d.attempts<d.max_attempts then d.status='pending'; d.available_epoch=now+delay; redis.call('ZADD',ready,d.available_epoch,d.id) else d.status='failed'; d.failed_at=stamp; redis.call('SADD',failed,d.id) end end
 write(d)
end
local function recover()
 local n=0; for _,i in ipairs(redis.call('ZRANGEBYSCORE',reserved,'-inf',now,'LIMIT',0,limit)) do local d=read(i); if d and d.status=='reserved' then finish(d,'Reservation expired',1); n=n+1 else redis.call('ZREM',reserved,i) end end; return n
end
if op=='push' then local d=cjson.decode(arg); local old=read(id); if old then if old.fingerprint~=d.fingerprint then return redis.error_reply('Idempotency key reused with different job') end; return id end; write(d); redis.call('ZADD',ready,d.available_epoch,id); return id end
if op=='recover' then return recover() end
if op=='pop' then
 recover()
 -- Drain the old list atomically. The existing job hash remains the source of truth.
 for n=1,limit do local i=redis.call('LINDEX',prefix..'pending:'..q,0); if not i then break end; local d=read(i); if d and d.status=='pending' then redis.call('ZADD',ready,d.available_epoch or now,i); redis.call('SADD',prefix..'queues',q) end; redis.call('LPOP',prefix..'pending:'..q) end
 for n=1,limit do local a=redis.call('ZRANGEBYSCORE',ready,'-inf',now,'LIMIT',0,1); if #a==0 then return false end; local i=a[1]; local d=read(i); if d and d.status=='pending' then d.status='reserved'; d.attempts=d.attempts+1; d.reservation_token=token; d.lease_until=now+lease; d.reserved_at=stamp; d.updated_at=stamp; write(d); redis.call('ZADD',reserved,d.lease_until,i); redis.call('ZREM',ready,i); return cjson.encode(d) else redis.call('ZREM',ready,i) end end; return false
end
if op=='cleanup' then local n=0; for _,i in ipairs(redis.call('ZRANGEBYSCORE',done,'-inf',now-tonumber(arg),'LIMIT',0,limit)) do local d=read(i); if d and d.status=='processed' then redis.call('DEL',prefix..'job:'..i); n=n+1 end; redis.call('ZREM',done,i) end; return n end
local d=read(id); if not d or d.queue~=q then return 0 end
if op=='legacy' then if d.status=='reserved' and type(d.lease_until)~='number' then finish(d,'Legacy reservation recovered',1); return 1 end; if d.status=='processed' then redis.call('ZADD',done,'NX',now,id) end; if d.status=='pending' then redis.call('ZADD',ready,'NX',d.available_epoch or now,id) end; redis.call('SADD',prefix..'queues',q); return 0 end
if op=='retry' then if d.status~='failed' then return 0 end; d.status='pending'; d.attempts=0; d.failed_at=cjson.null; d.last_error=cjson.null; d.reserved_at=cjson.null; d.reservation_token=cjson.null; d.lease_until=cjson.null; d.available_epoch=now; d.updated_at=stamp; write(d); redis.call('SREM',failed,id); redis.call('ZADD',ready,now,id); return 1 end
if d.status~='reserved' or d.reservation_token~=token or tonumber(d.lease_until)<=now then return 0 end
if op=='heartbeat' then d.lease_until=now+lease; d.updated_at=stamp; write(d); redis.call('ZADD',reserved,d.lease_until,id); return 1 end
if op=='complete' then finish(d,'',0); return 1 end
if op=='fail' then local e=cjson.decode(arg); finish(d,e.error,e.delay); return 1 end
return 0
LUA;
    private function exec(string $op,string $q,string $id='',string $token='',string $arg='',int $limit=100): mixed
    {
        $this->redis->clearLastError();$r=$this->redis->eval(self::SCRIPT,[$this->prefix,$q,$op,$id,$token,(string)QueueOptions::lease($this->leaseSeconds),$arg,(string)max(1,min(1000,$limit)),gmdate(DATE_ATOM)],0);
        if($this->redis->getLastError())throw new \RuntimeException('Redis queue transition failed: '.$this->redis->getLastError());return $r;
    }
    public function push(string $job,array $payload=[],?string $queue=null): string { $q=QueueOptions::queue($queue);$id=QueueOptions::id($q,$job,$payload);$d=['id'=>$id,'name'=>$job,'payload'=>$payload,'queue'=>$q,'attempts'=>0,'max_attempts'=>QueueOptions::maxAttempts($payload),'status'=>'pending','fingerprint'=>QueueOptions::fingerprint($job,$payload),'available_epoch'=>QueueOptions::available($payload),'created_at'=>gmdate(DATE_ATOM),'updated_at'=>gmdate(DATE_ATOM)];$result=$this->exec('push',$q,$id,'',json_encode($d,JSON_THROW_ON_ERROR));if($result!==$id)throw new \RuntimeException('Redis enqueue was not confirmed');return $id; }
    public function pop(?string $queue=null): ?QueuedJob { $q=QueueOptions::queue($queue);$r=$this->exec('pop',$q,'',QueueOptions::token());if(!is_string($r))return null;$d=json_decode($r,true,512,JSON_THROW_ON_ERROR);return new QueuedJob($d['id'],$d['name'],$d['payload'],$q,$d['attempts'],$d['max_attempts'],gmdate(DATE_ATOM,$d['available_epoch']??time()),$d['reserved_at'],null,$d['reservation_token'],$d['lease_until']); }
    public function heartbeat(QueuedJob $job): bool {return $this->exec('heartbeat',$job->queue,$job->id,$job->reservationToken??'')===1;}
    public function complete(QueuedJob $job): void {if($this->exec('complete',$job->queue,$job->id,$job->reservationToken??'')!==1)throw new \RuntimeException('Queue reservation lost');}
    public function fail(QueuedJob $job,string $error,int $backoffSeconds=60): void {if($this->exec('fail',$job->queue,$job->id,$job->reservationToken??'',json_encode(['error'=>substr($error?:'Job failed',0,2000),'delay'=>max(1,$backoffSeconds)],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE))!==1)throw new \RuntimeException('Queue reservation lost');}
    public function recover(?string $queue=null,int $limit=100): int {return (int)$this->exec('recover',QueueOptions::queue($queue),'','','',$limit);}
    public function retry(string $id,?string $queue=null): bool {QueueOptions::validateId($id);return $this->exec('retry',QueueOptions::queue($queue),$id)===1;}
    public function failed(?string $queue=null): array {$q=QueueOptions::queue($queue);$items=[];foreach($this->redis->sMembers($this->prefix.'failed:'.$q)?:[]as $id){$r=$this->redis->hGet($this->prefix.'job:'.$id,'data');if(is_string($r)){$d=json_decode($r,true,512,JSON_THROW_ON_ERROR);if($d['status']==='failed')$items[]=$d;}}return $items;}
    public function diagnostics(): array {$qs=[];$oldest=0;foreach($this->redis->sMembers($this->prefix.'queues')?:[]as $q){$first=$this->redis->zRange($this->prefix.'ready:'.$q,0,0,true);if($first)$oldest=max($oldest,time()-(int)reset($first));$qs[$q]=['pending'=>$this->redis->zCard($this->prefix.'ready:'.$q)+$this->redis->lLen($this->prefix.'pending:'.$q),'reserved'=>$this->redis->zCard($this->prefix.'reserved:'.$q),'processed'=>$this->redis->zCard($this->prefix.'processed:'.$q),'failed'=>$this->redis->sCard($this->prefix.'failed:'.$q)];}return ['driver'=>'redis','oldest_ready_age_seconds'=>$oldest,'connected'=>$this->redis->isConnected(),'queues'=>$qs,'lease_seconds'=>QueueOptions::lease($this->leaseSeconds)];}
    public function cleanupProcessed(int $olderThanSeconds=604800): int {$n=0;foreach($this->redis->sMembers($this->prefix.'queues')?:[]as $q){for($i=0;$i<100;$i++){$count=(int)$this->exec('cleanup',$q,'','',(string)max(1,$olderThanSeconds),1000);$n+=$count;if($count<1000)break;}}return $n;}
    /** Offline migration of 1.2.1 hashes, including orphaned reservations. Stop old workers first. */
    public function migrateLegacy(): int {$cursor=null;$n=0;do{$keys=$this->redis->scan($cursor,$this->prefix.'job:*',200);foreach($keys?:[]as $key){$r=$this->redis->hGet($key,'data');if(!is_string($r))continue;$d=json_decode($r,true,512,JSON_THROW_ON_ERROR);$n+=(int)$this->exec('legacy',QueueOptions::queue($d['queue']),$d['id']);}}while($cursor!==0);return $n;}
}
