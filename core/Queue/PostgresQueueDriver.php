<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

use PDO;

final readonly class PostgresQueueDriver implements LeaseQueueDriverInterface
{
    public function __construct(private PDO $pdo,private int $leaseSeconds=300) {}
    public function push(string $job,array $payload=[],?string $queue=null): string
    {
        $q=QueueOptions::queue($queue);$id=QueueOptions::id($q,$job,$payload);
        $r=$this->run('INSERT INTO ce_jobs (id,queue,name,payload,available_at,max_attempts,created_at,updated_at) VALUES (:id,:q,:name,CAST(:payload AS jsonb),to_timestamp(:available),:max,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT (id) DO UPDATE SET id=ce_jobs.id WHERE ce_jobs.name=EXCLUDED.name AND ce_jobs.payload=EXCLUDED.payload RETURNING id',['id'=>$id,'q'=>$q,'name'=>$job,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'available'=>QueueOptions::available($payload),'max'=>QueueOptions::maxAttempts($payload)]);if(!$r->fetchColumn())throw new \RuntimeException('Idempotency key reused with different job');return $id;
    }
    public function pop(?string $queue=null): ?QueuedJob
    {
        $q=QueueOptions::queue($queue);$this->recover($q);
        $row=$this->run("WITH candidate AS (SELECT id FROM ce_jobs WHERE queue=:q AND status='pending' AND available_at<=clock_timestamp() ORDER BY available_at,created_at,id LIMIT 1 FOR UPDATE SKIP LOCKED) UPDATE ce_jobs j SET status='reserved',reserved_at=clock_timestamp(),attempts=j.attempts+1,reservation_token=:token,lease_until=clock_timestamp()+CAST(:lease AS integer)*interval '1 second',updated_at=clock_timestamp() FROM candidate c WHERE j.id=c.id RETURNING j.*",['q'=>$q,'token'=>QueueOptions::token(),'lease'=>QueueOptions::lease($this->leaseSeconds)])->fetch(PDO::FETCH_ASSOC);
        return $row?$this->job($row):null;
    }
    public function heartbeat(QueuedJob $job): bool {return $this->ownedUpdate($job,"lease_until=clock_timestamp()+CAST(:lease AS integer)*interval '1 second',updated_at=clock_timestamp()",['lease'=>QueueOptions::lease($this->leaseSeconds)])===1;}
    public function complete(QueuedJob $job): void {if($this->ownedUpdate($job,"status='processed',processed_at=clock_timestamp(),updated_at=clock_timestamp(),reserved_at=NULL,reservation_token=NULL,lease_until=NULL")!==1)throw new \RuntimeException('Queue reservation lost');}
    public function fail(QueuedJob $job,string $error,int $backoffSeconds=60): void
    {
        if($this->ownedUpdate($job,"status=CASE WHEN attempts<max_attempts THEN 'pending' ELSE 'failed' END,available_at=clock_timestamp()+CAST(:delay AS integer)*interval '1 second',failed_at=CASE WHEN attempts>=max_attempts THEN clock_timestamp() ELSE NULL END,last_error=:error,updated_at=clock_timestamp(),reserved_at=NULL,reservation_token=NULL,lease_until=NULL",['delay'=>max(1,$backoffSeconds),'error'=>QueueOptions::error($error)])!==1)throw new \RuntimeException('Queue reservation lost');
    }
    private function ownedUpdate(QueuedJob $j,string $set,array $params=[]): int {return $this->run("UPDATE ce_jobs SET $set WHERE id=:id AND queue=:q AND status='reserved' AND reservation_token=:token AND lease_until>clock_timestamp()",$params+['id'=>$j->id,'q'=>$j->queue,'token'=>$j->reservationToken])->rowCount();}
    public function recover(?string $queue=null,int $limit=100): int
    {
        return $this->run("WITH expired AS (SELECT id FROM ce_jobs WHERE queue=:q AND status='reserved' AND COALESCE(lease_until,reserved_at+CAST(:lease AS integer)*interval '1 second','-infinity'::timestamptz)<=clock_timestamp() ORDER BY lease_until NULLS FIRST,id LIMIT CAST(:lim AS integer) FOR UPDATE SKIP LOCKED) UPDATE ce_jobs j SET status=CASE WHEN attempts<max_attempts THEN 'pending' ELSE 'failed' END,available_at=clock_timestamp()+interval '1 second',failed_at=CASE WHEN attempts>=max_attempts THEN clock_timestamp() ELSE NULL END,last_error='Reservation expired',updated_at=clock_timestamp(),reserved_at=NULL,reservation_token=NULL,lease_until=NULL FROM expired e WHERE j.id=e.id",['q'=>QueueOptions::queue($queue),'lease'=>QueueOptions::lease($this->leaseSeconds),'lim'=>max(1,min(1000,$limit))])->rowCount();
    }
    public function retry(string $id,?string $queue=null): bool {QueueOptions::validateId($id);return $this->run("UPDATE ce_jobs SET status='pending',attempts=0,reserved_at=NULL,reservation_token=NULL,lease_until=NULL,available_at=clock_timestamp(),failed_at=NULL,last_error=NULL,updated_at=clock_timestamp() WHERE id=:id AND queue=:q AND status='failed'",['id'=>$id,'q'=>QueueOptions::queue($queue)])->rowCount()>0;}
    public function failed(?string $queue=null): array {return $this->run("SELECT * FROM ce_jobs WHERE queue=:q AND status='failed' ORDER BY failed_at DESC LIMIT 1000",['q'=>QueueOptions::queue($queue)])->fetchAll(PDO::FETCH_ASSOC);}
    public function diagnostics(): array {$queues=[];foreach($this->pdo->query('SELECT queue,status,COUNT(*) AS count FROM ce_jobs GROUP BY queue,status')->fetchAll(PDO::FETCH_ASSOC)as $r)$queues[$r['queue']][$r['status']]=(int)$r['count'];$age=(int)$this->pdo->query("SELECT COALESCE(floor(extract(epoch FROM clock_timestamp()-MIN(available_at))),0) FROM ce_jobs WHERE status='pending' AND available_at<=clock_timestamp()")->fetchColumn();return ['driver'=>'pgsql','oldest_ready_age_seconds'=>max(0,$age),'queues'=>$queues,'lease_seconds'=>QueueOptions::lease($this->leaseSeconds)];}
    public function cleanupProcessed(int $olderThanSeconds=604800): int {return $this->run("DELETE FROM ce_jobs WHERE status='processed' AND processed_at<clock_timestamp()-CAST(:age AS integer)*interval '1 second'",['age'=>max(1,$olderThanSeconds)])->rowCount();}
    private function run(string $sql,array $args): \PDOStatement {$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    private function job(array $r): QueuedJob {return new QueuedJob($r['id'],$r['name'],json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR),$r['queue'],(int)$r['attempts'],(int)$r['max_attempts'],$r['available_at'],$r['reserved_at'],null,$r['reservation_token'],strtotime($r['lease_until']));}
}
