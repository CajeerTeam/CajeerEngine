<?php
declare(strict_types=1);
namespace CajeerEngine\Queue;

use CajeerEngine\Integration\WebhookRepository;
use CajeerEngine\Integration\WebhookDispatcher;
use CajeerEngine\Search\SearchIndexer;

final class QueueWorker
{
    private JobHandlerRegistry $handlers;
    private bool $stopping=false;
    public function __construct(private readonly ManagedQueueDriverInterface $queue,private readonly SearchIndexer $searchIndexer,private readonly WebhookRepository $webhooks,private readonly WebhookDispatcher $dispatcher,private readonly ?\CajeerEngine\Extension\Runtime\PlatformServices $platform=null)
    {
        $this->handlers=new JobHandlerRegistry();
        $this->handlers->register('log.message',fn($p,$c)=>$this->logMessage($p,$c));
        $this->handlers->register('search.index_all',fn()=> $this->searchIndexer->indexAll());
        $this->handlers->register('webhooks.dispatch_pending',function(){if(!$this->webhooks->enqueuePending($this->queue,50)['ok'])throw new \RuntimeException('Outbox handoff failed; events retained for retry');});
        $this->handlers->register('webhook.dispatch',fn($p,$c)=>$this->dispatchWebhookJob($p,$c));
        $this->handlers->register('webhook.deliver',fn($p,$c)=>$this->deliver($p,$c));
    }
    public function registry(): JobHandlerRegistry {return $this->handlers;}
    public function stop(): void {$this->stopping=true;}
    public function work(?string $queue=null,int $limit=10): array
    {
        $processed=0;$failed=0;$errors=[];$lost=0;$signals=PHP_SAPI==='cli'&&function_exists('pcntl_signal')&&function_exists('pcntl_alarm');
        if($signals){$async=pcntl_async_signals(true);$old=[];foreach([SIGTERM,SIGINT,SIGALRM]as $s)$old[$s]=pcntl_signal_get_handler($s);pcntl_signal(SIGTERM,fn()=> $this->stop());pcntl_signal(SIGINT,fn()=> $this->stop());pcntl_signal(SIGALRM,static function(){throw new \RuntimeException('Job execution timeout');});}
        try {
            for($i=0;$i<max(1,min(1000,$limit))&&!$this->stopping;$i++) {
                $job=$this->queue->pop($queue);if($job===null)break;$context=new JobContext($job,$this->queue);
                try {
                    $context->heartbeat();
                    if($signals){$timeout=max(1,(int)(getenv('QUEUE_JOB_TIMEOUT_SECONDS')?:120));$lease=$job->leaseUntil===null?$timeout:max(1,$job->leaseUntil-time()-1);pcntl_alarm(min($timeout,$lease));}
                    try {if(str_starts_with($job->name,'extension.')){if($this->platform===null)throw new \RuntimeException('Extension runtime unavailable');$this->platform->handleJob($job->name,$job->payload,$context);}else $this->handlers->handle($job,$context);}
                    finally {if($signals)pcntl_alarm(0);}
                    $context->heartbeat();$this->queue->complete($job);$processed++;
                }catch(\Throwable $e){$failed++;$errors[]=['id'=>$job->id,'name'=>$job->name,'error'=>QueueOptions::error($e->getMessage())];try{$this->queue->fail($job,$e->getMessage(),QueueOptions::backoff($job->attempts));}catch(\Throwable $ack){$lost++;$errors[]=['id'=>$job->id,'error'=>'Cannot persist failure: '.QueueOptions::error($ack->getMessage())];}}
            }
        }finally{if($signals){pcntl_alarm(0);foreach($old as $s=>$handler)pcntl_signal($s,$handler);pcntl_async_signals($async);}}
        return ['processed'=>$processed,'failed'=>$failed,'lost_reservations'=>$lost,'stopping'=>$this->stopping,'errors'=>$errors,'diagnostics'=>$this->queue->diagnostics()];
    }
    private function logMessage(array $payload,JobContext $context): void
    {
        $path=$this->webhooks->rootPath().'/storage/logs/queue.log';\CajeerEngine\Update\OperationFiles::dir(dirname($path),0770);
        $h=fopen($path,'c+');if(!$h)throw new \RuntimeException('Cannot open job log');
        try{if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock job log');$marker='['.$context->job->id.']';while(($line=fgets($h))!==false)if(str_contains($line,' '.$marker.' ')&&str_ends_with($line,"\n"))return;
            $line='['.gmdate(DATE_ATOM).'] '.$marker.' '.str_replace(["\r","\n"],' ',(string)($payload['message']??'empty')).PHP_EOL;
            if(fseek($h,0,SEEK_END)!==0||fwrite($h,$line)!==strlen($line)||!fflush($h)||!fsync($h))throw new \RuntimeException('Cannot persist job log');
        }finally{fclose($h);}
    }
    private function dispatchWebhookJob(array $p,JobContext $c): void
    {
        $event=(string)($p['event']??'manual.test');$body=is_array($p['payload']??null)?$p['payload']:[];
        foreach($this->webhooks->matching($event)as $w){$c->heartbeat();$this->queue->push('webhook.deliver',['event'=>$event,'payload'=>$body,'webhook_id'=>$w['id'],'event_id'=>$c->job->id,'_idempotency_key'=>'webhook:'.$c->job->id.':'.$w['id']],$c->job->queue);}
    }
    private function deliver(array $p,JobContext $c): void
    {
        $id=(string)($p['webhook_id']??'');$event=(string)($p['event']??'');$eventId=(string)($p['event_id']??$c->job->id);
        $w=$this->webhooks->find($id);if(!$w||!($w['enabled']??false))return;
        $this->webhooks->deliverOnce($id,$eventId,function()use($w,$event,$p,$eventId,$c){$c->heartbeat();$r=$this->dispatcher->dispatchWebhook($w,$event,is_array($p['payload']??null)?$p['payload']:[],$eventId);if(!$r['ok'])throw new \RuntimeException('Webhook delivery failed (HTTP '.(int)$r['status_code'].')');return $r;});
    }
}
