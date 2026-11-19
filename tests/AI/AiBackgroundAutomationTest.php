<?php

declare(strict_types=1);

namespace CajeerEngine\Tests\AI;

use CajeerEngine\AI\AiBackgroundAutomation;
use CajeerEngine\AI\AiChat;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Queue\FileQueueDriver;
use CajeerEngine\Queue\JobContext;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Queue\QueuedJob;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

final class TestAutomationAiProvider extends OpenAiClient
{
    public int $calls = 0;
    public bool $reject = false;

    public function complete(string $key, string $model, array $input, int $maxOutput, ?callable $onDelta = null): array
    {
        ++$this->calls;
        if ($this->reject) throw new \RuntimeException('secret:sk-never-store-in-queue');
        return ['text'=>'Background scenario completed','usage'=>['input_tokens'=>25,'output_tokens'=>15,'cached_input_tokens'=>0],'response_id'=>'resp_test'];
    }
}

final class AiBackgroundAutomationTest extends TestCase
{
    private string $root;
    private ?string $previousDb;
    private ?string $previousPath;
    private ?string $previousKey;

    protected function setUp(): void
    {
        if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) self::markTestSkipped('pdo_sqlite required.');
        $this->root=sys_get_temp_dir().'/ce-ai-auto-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/storage',0775,true);
        $this->previousDb=getenv('DB_DRIVER')?:null;
        $this->previousPath=getenv('SQLITE_DATABASE')?:null;
        $this->previousKey=getenv('OPENAI_API_KEY')?:null;
        putenv('DB_DRIVER=sqlite');
        putenv('SQLITE_DATABASE='.$this->root.'/test.sqlite');
        putenv('OPENAI_API_KEY=sk-test-automation-test-key');
    }

    protected function tearDown(): void
    {
        foreach (['DB_DRIVER'=>$this->previousDb??null,'SQLITE_DATABASE'=>$this->previousPath??null,'OPENAI_API_KEY'=>$this->previousKey??null] as $name=>$value) {
            $value===null?putenv($name):putenv($name.'='.$value);
        }
        if (isset($this->root) && is_dir($this->root)) {
            $iter=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($iter as $entry) $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
            rmdir($this->root);
        }
    }

    private function setupEngine(): array
    {
        $config=new ConfigRepository(dirname(__DIR__,2));
        $db=new DatabaseManager($config);
        $pdo=$db->connection();
        foreach (['0001_initial_schema','0031_ai_chat','0037_ai_tools','0040_ai_cost_control','0041_ai_background_automation','0042_ai_automation_delivery_acceptance','0043_ai_automation_ga'] as $name) {
            $pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/sqlite/'.$name.'.sql'));
        }
        $user=Uuid::v4(); $other=Uuid::v4(); $role=Uuid::v4();
        foreach ([$user,$other] as $id) {
            $pdo->prepare('INSERT INTO ce_users (id,email,name,password_hash) VALUES (?,?,?,?)')->execute([$id,$id.'@example.test','Admin','hash']);
        }
        $pdo->prepare('INSERT INTO ce_roles (id,handle,name,permissions) VALUES (?,?,?,?)')->execute([$role,'editor','Editor','["content:read","content:write"]']);
        foreach ([$user,$other] as $id) $pdo->prepare('INSERT INTO ce_user_roles (user_id,role_id) VALUES (?,?)')->execute([$id,$role]);
        $provider=new TestAutomationAiProvider();
        $chat=new AiChat($db,new AiSettings($this->root),$provider);
        $queue=new FileQueueDriver($this->root);
        return [new AiBackgroundAutomation($db,$queue,$chat),$queue,$pdo,$provider,$user,$other,$db,$chat];
    }

    public function testScheduledAndManualExecutionPersistsConversationAndCannotReplay(): void
    {
        [$automation,$queue,$pdo,$provider,$owner,$other]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Morning digest','prompt'=>'Provide a daily summary','interval_minutes'=>1440,'enabled'=>true]);
        self::assertCount(1,$automation->list($owner));
        self::assertCount(0,$automation->list($other));
        try { $automation->get($other,$task['id']); self::fail('Cross-owner access was allowed'); }
        catch (\DomainException) {}

        $manual=$automation->runNow($owner,$task['id']);
        self::assertSame('queued',$manual['status']);
        $job=$queue->pop(); self::assertNotNull($job);
        $automation->execute($manual['id'],new JobContext($job,$queue));
        $queue->complete($job);
        self::assertSame('succeeded',$automation->runs($owner,$task['id'])[0]['status']);
        self::assertNotEmpty($automation->runs($owner,$task['id'])[0]['conversation_id']);
        $automation->execute($manual['id'],new JobContext($job,$queue));
        self::assertSame(1,$provider->calls,'A duplicate reservation must never repeat a paid AI generation');

        $pdo->prepare('UPDATE ce_ai_automations SET next_run_at = ? WHERE id = ?')->execute([time()-10,$task['id']]);
        $result=$automation->dispatch();
        self::assertSame(1,$result['created']);
        self::assertSame(0,$automation->dispatch()['created']);
        $scheduled=$queue->pop(); self::assertNotNull($scheduled);
        $automation->execute($scheduled->payload['run_id'],new JobContext($scheduled,$queue));
        $queue->complete($scheduled);
        self::assertSame(2,$provider->calls);
        self::assertCount(2,$automation->runs($owner,$task['id']));
        $balance=$pdo->query('SELECT COUNT(*) FROM ce_ai_cost_events')->fetchColumn();
        self::assertSame(2,(int)$balance,'Scheduled tasks must settle the shared AI cost ledger');
        self::assertSame('background_automation',$pdo->query('SELECT module FROM ce_ai_cost_events LIMIT 1')->fetchColumn());
    }

    public function testPauseCancelsQueuedRunsAndRevokingRolesPreventsExecution(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Check','prompt'=>'Analyze new entries','interval_minutes'=>60,'enabled'=>true]);
        $run=$automation->runNow($owner,$task['id']);
        $paused=$automation->update($owner,$task['id'],['revision'=>$task['revision'],'enabled'=>false]);
        self::assertFalse($paused['enabled']);
        $job=$queue->pop(); self::assertNotNull($job);
        $automation->execute($run['id'],new JobContext($job,$queue));
        self::assertSame(0,$provider->calls);
        self::assertSame('canceled',$automation->runs($owner,$task['id'])[0]['status']);
        $automation->update($owner,$task['id'],['revision'=>$paused['revision'],'enabled'=>true]);
        $pdo->prepare('DELETE FROM ce_user_roles WHERE user_id = ?')->execute([$owner]);
        $pdo->prepare('UPDATE ce_ai_automations SET next_run_at = ? WHERE id = ?')->execute([time()-5,$task['id']]);
        self::assertSame(0,$automation->dispatch()['created']);
        self::assertFalse($automation->get($owner,$task['id'])['enabled']);
    }
    public function testDeadLetterIsFinalAndCannotRepeatProviderCall(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Weekly','prompt'=>'Create report','interval_minutes'=>60]);
        $run=$automation->runNow($owner,$task['id']);
        $job=$queue->pop(); self::assertNotNull($job);
        $queue->fail($job,'transport failure');
        $state=$automation->dispatch();
        self::assertSame(1,$state['dead_queue_jobs']);
        self::assertSame('failed',$automation->runs($owner,$task['id'])[0]['status']);
        self::assertTrue($queue->retry($job->id));
        $retry=$queue->pop(); self::assertNotNull($retry);
        $automation->execute($run['id'],new JobContext($retry,$queue));
        self::assertSame(0,$provider->calls);
    }

    public function testCommittedManualRunRecoversAfterQueueOutage(): void
    {
        [$automation,$queue,$pdo,$provider,$owner,$other,$db,$chat]=$this->setupEngine();
        $unstable=new class($queue) implements ManagedQueueDriverInterface {
            public bool $down = true;
            public function __construct(private FileQueueDriver $underlying) {}
            public function push(string $job, array $payload=[], ?string $queue=null): string {
                if ($this->down) throw new \RuntimeException('transport down');
                return $this->underlying->push($job,$payload,$queue);
            }
            public function pop(?string $queue=null): ?QueuedJob { return $this->underlying->pop($queue); }
            public function complete(QueuedJob $job): void { $this->underlying->complete($job); }
            public function fail(QueuedJob $job, string $error, int $backoffSeconds=60): void { $this->underlying->fail($job,$error,$backoffSeconds); }
            public function retry(string $id, ?string $queue=null): bool { return $this->underlying->retry($id,$queue); }
            public function failed(?string $queue=null): array { return $this->underlying->failed($queue); }
            public function diagnostics(): array { return $this->underlying->diagnostics(); }
            public function cleanupProcessed(int $olderThanSeconds=604800): int { return $this->underlying->cleanupProcessed($olderThanSeconds); }
        };
        $service=new AiBackgroundAutomation($db,$unstable,$chat);
        $task=$service->create($owner,['title'=>'Report','prompt'=>'Daily status','interval_minutes'=>60]);
        $run=$service->runNow($owner,$task['id']);
        self::assertFalse($run['enqueued']);
        self::assertSame('queued',$service->runs($owner,$task['id'])[0]['status']);
        self::assertNull($queue->pop());
        $unstable->down=false;
        $result=$service->dispatch();
        self::assertSame(1,$result['offered']);
        $job=$queue->pop(); self::assertNotNull($job);
        $service->execute($run['id'],new JobContext($job,$queue));
        $queue->complete($job);
        self::assertSame(1,$provider->calls);
        self::assertSame('succeeded',$service->runs($owner,$task['id'])[0]['status']);
    }

    public function testInactiveOwnerIsRejectedAndRecurringScheduleDisabled(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Safety','prompt'=>'Summary','interval_minutes'=>60]);
        $pdo->prepare('UPDATE ce_users SET status = ? WHERE id = ?')->execute(['disabled',$owner]);
        try { $automation->runNow($owner,$task['id']); self::fail('Disabled owner queued a run'); }
        catch (\DomainException $e) { self::assertStringContainsString('active',$e->getMessage()); }
        $pdo->prepare('UPDATE ce_ai_automations SET next_run_at = ? WHERE id = ?')->execute([time()-1,$task['id']]);
        self::assertSame(0,$automation->dispatch()['created']);
        self::assertFalse($automation->get($owner,$task['id'])['enabled']);
        self::assertSame(0,$provider->calls);
    }

    public function testOldQueueDeliveryCannotCallOpenAiAndPromptHistoryExpires(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Private','prompt'=>'Private sample data','interval_minutes'=>60]);
        $old=$automation->runNow($owner,$task['id']);
        $pdo->prepare('UPDATE ce_ai_automation_runs SET queued_at = ? WHERE id = ?')->execute([time()-86401,$old['id']]);
        self::assertSame(1,$automation->dispatch()['expired_queued']);
        $oldJob=$queue->pop(); self::assertNotNull($oldJob);
        $automation->execute($old['id'],new JobContext($oldJob,$queue));
        $queue->complete($oldJob);
        self::assertSame(0,$provider->calls);
        $fresh=$automation->runNow($owner,$task['id']);
        $newJob=$queue->pop(); self::assertNotNull($newJob);
        $automation->execute($fresh['id'],new JobContext($newJob,$queue));
        $queue->complete($newJob);
        $pdo->prepare('UPDATE ce_ai_automation_runs SET finished_at = ? WHERE id = ?')->execute([time()-31*86400,$fresh['id']]);
        self::assertSame(1,$automation->dispatch()['redacted']);
        $stmt=$pdo->prepare('SELECT prompt_snapshot, status FROM ce_ai_automation_runs WHERE id = ?'); $stmt->execute([$fresh['id']]);
        self::assertSame(['prompt_snapshot'=>'[expired]','status'=>'succeeded'],$stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testProviderFailureIsSanitizedAndCannotBeReplayed(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $provider->reject=true;
        $task=$automation->create($owner,['title'=>'Bad','prompt'=>'Do not expose the key','interval_minutes'=>60]);
        $run=$automation->runNow($owner,$task['id']);
        $job=$queue->pop(); self::assertNotNull($job);
        try { $automation->execute($run['id'],new JobContext($job,$queue)); self::fail('Provider was expected to fail'); }
        catch (\RuntimeException $e) {
            self::assertStringNotContainsString('secret:', $e->getMessage());
            $queue->fail($job,$e->getMessage());
        }
        $runHistory=$automation->runs($owner,$task['id']);
        self::assertSame('failed',$runHistory[0]['status']);
        self::assertStringNotContainsString('secret:',(string)$runHistory[0]['error']);
        $failed=$queue->failed();
        self::assertStringNotContainsString('secret:',(string)$failed[0]['last_error']);
        self::assertTrue($queue->retry($job->id));
        $retried=$queue->pop();self::assertNotNull($retried);
        $automation->execute($run['id'],new JobContext($retried,$queue));
        self::assertSame(1,$provider->calls);
    }

    public function testQueueOfferFairnessPersistsAcrossSchedulerTicks(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Fairness','prompt'=>'Routine work','interval_minutes'=>60]);
        // Insert 101 distinct queued deliveries to model a sustained transport backlog;
        // a single scheduler tick can offer only 100.
        $insert=$pdo->prepare("INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,NULL,'queued',?)");
        $ids=[];
        for ($i=0;$i<101;$i++) {
            $id=Uuid::v4();$ids[]=$id;
            $insert->execute([$id,$task['id'],$owner,'Routine work',time()-100]);
        }
        self::assertSame(100,$automation->dispatch()['offered']);
        $stmt=$pdo->prepare('SELECT last_offer_at FROM ce_ai_automation_runs WHERE id = ?');
        $stmt->execute([$ids[100]]); self::assertNull($stmt->fetchColumn()?:null);
        // The previously unoffered run must win over the oldest 100 on next tick.
        self::assertSame(100,$automation->dispatch()['offered']);
        $stmt->execute([$ids[100]]);self::assertNotNull($stmt->fetchColumn());
    }

    public function testMismatchedRunOwnerNeverCallsProvider(): void
    {
        [$automation,$queue,$pdo,$provider,$owner,$other]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Guard','prompt'=>'Owner only','interval_minutes'=>60]);
        // Corrupted/out-of-band rows cannot bill a different active user even if
        // their user_id and automation_id each satisfy independent foreign keys.
        $id=Uuid::v4();
        $pdo->prepare("INSERT INTO ce_ai_automation_runs (id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at) VALUES (?,?,?,?,NULL,'queued',?)")
            ->execute([$id,$task['id'],$other,'Charge wrong user',time()]);
        $jobId=$queue->push('ai.automation.execute',['run_id'=>$id,'_idempotency_key'=>'ai.automation.'.$id,'_max_attempts'=>1]);
        $job=$queue->pop(); self::assertNotNull($job);
        self::assertSame($jobId,$job->id);
        $automation->execute($id,new JobContext($job,$queue));
        self::assertSame(0,$provider->calls);
        self::assertSame('canceled',$pdo->query("SELECT status FROM ce_ai_automation_runs WHERE id = ".$pdo->quote($id))->fetchColumn());
    }

    public function testConcurrentCapacityAndDailyQuotaEnforced(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=null;
        for ($i=0;$i<20;$i++) $task=$automation->create($owner,['title'=>'Schedule '.$i,'prompt'=>'Summary','interval_minutes'=>60]);
        self::assertCount(20,$automation->list($owner));
        try { $automation->create($owner,['title'=>'Twenty first','prompt'=>'Too many','interval_minutes'=>60]); self::fail('Owner exceeded cap'); }
        catch (\DomainException $e) { self::assertStringContainsString('Maximum 20',$e->getMessage()); }
        $insert=$pdo->prepare("INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at,finished_at) VALUES (?,?,?,?,NULL,'succeeded',?,?)");
        for($i=0;$i<100;$i++) $insert->execute([Uuid::v4(),$task['id'],$owner,'Budgeted prompt',time(),time()]);
        try { $automation->runNow($owner,$task['id']); self::fail('Owner exceeded 100/day cap'); }
        catch (\DomainException $e) { self::assertStringContainsString('Daily',$e->getMessage()); }
        self::assertSame(0,$provider->calls);
    }

    public function testDeletionErasesSoftDeletedPromptAndQueuedRunCopies(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Private schedule','prompt'=>'Private onboarding credentials','interval_minutes'=>60]);
        $run=$automation->runNow($owner,$task['id']);
        $automation->delete($owner,$task['id']);
        $row=$pdo->query('SELECT prompt, deleted_at FROM ce_ai_automations WHERE id = '.$pdo->quote($task['id']))->fetch(PDO::FETCH_ASSOC);
        self::assertSame('[deleted]',$row['prompt']);
        self::assertNotNull($row['deleted_at']);
        self::assertSame('[deleted]',$pdo->query('SELECT prompt_snapshot FROM ce_ai_automation_runs WHERE id = '.$pdo->quote($run['id']))->fetchColumn());
        $job=$queue->pop(); self::assertNotNull($job);
        $automation->execute($run['id'],new JobContext($job,$queue));
        $queue->complete($job);
        self::assertSame(0,$provider->calls);
        self::assertSame('canceled',$pdo->query('SELECT status FROM ce_ai_automation_runs WHERE id = '.$pdo->quote($run['id']))->fetchColumn());
        $automation->dispatch();
        self::assertSame('[deleted]',$pdo->query('SELECT prompt_snapshot FROM ce_ai_automation_runs WHERE id = '.$pdo->quote($run['id']))->fetchColumn());
    }

    public function testEmergencySwitchStopsNewAndPendingGenerations(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Emergency','prompt'=>'Not billable when disabled','interval_minutes'=>60]);
        $run=$automation->runNow($owner,$task['id']);
        $before=getenv('AI_AUTOMATION_ENABLED');
        try {
            putenv('AI_AUTOMATION_ENABLED=0');
            $result=$automation->dispatch();
            self::assertFalse($result['enabled']);
            self::assertSame(0,$result['offered']);
            try { $automation->runNow($owner,$task['id']); self::fail('Kill switch was ignored'); }
            catch (\DomainException $e) { self::assertStringContainsString('disabled',$e->getMessage()); }
            $job=$queue->pop(); self::assertNotNull($job);
            $automation->execute($run['id'],new JobContext($job,$queue));
            $queue->complete($job);
            self::assertSame('canceled',$automation->runs($owner,$task['id'])[0]['status']);
            self::assertSame(0,$provider->calls);
        } finally {
            $before===false ? putenv('AI_AUTOMATION_ENABLED') : putenv('AI_AUTOMATION_ENABLED='.$before);
        }
    }

    public function testRestoredWorkerRejectsExpiredDeliveryWithoutScheduler(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Late','prompt'=>'Not after the deadline','interval_minutes'=>60]);
        $run=$automation->runNow($owner,$task['id']);
        $pdo->prepare('UPDATE ce_ai_automation_runs SET queued_at=? WHERE id=?')->execute([time()-86401,$run['id']]);
        $job=$queue->pop(); self::assertNotNull($job);
        $automation->execute($run['id'],new JobContext($job,$queue));
        self::assertSame('failed',$automation->runs($owner,$task['id'])[0]['status']);
        self::assertSame(0,$provider->calls);
    }

    public function testForgedJobCannotClaimQueuedRun(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Validate','prompt'=>'Trusted only','interval_minutes'=>60]);
        $run=$automation->runNow($owner,$task['id']);
        $malicious=new QueuedJob(Uuid::v4(),'ai.automation.execute',['run_id'=>$run['id']],'default',1,1);
        try { $automation->execute($run['id'],new JobContext($malicious,$queue)); self::fail('Invalid job was accepted'); }
        catch (\DomainException $e) { self::assertStringContainsString('Invalid AI automation',$e->getMessage()); }
        self::assertSame('queued',$automation->runs($owner,$task['id'])[0]['status']);
        self::assertSame(0,$provider->calls);
    }

    public function testBrokenDeadLetterScanDoesNotBlockDurableScheduledRuns(): void
    {
        [$automation,$queue,$pdo,$provider,$owner,$other,$db,$chat]=$this->setupEngine();
        $failing=new class($queue) implements ManagedQueueDriverInterface {
            public function __construct(private FileQueueDriver $driver) {}
            public function push(string $job, array $payload=[], ?string $queue=null): string { return $this->driver->push($job,$payload,$queue); }
            public function pop(?string $queue=null): ?QueuedJob { return $this->driver->pop($queue); }
            public function complete(QueuedJob $job): void { $this->driver->complete($job); }
            public function fail(QueuedJob $job, string $error, int $backoffSeconds=60): void { $this->driver->fail($job,$error,$backoffSeconds); }
            public function retry(string $id, ?string $queue=null): bool { return $this->driver->retry($id,$queue); }
            public function failed(?string $queue=null): array { throw new \RuntimeException('Unavailable broker scan'); }
            public function diagnostics(): array { return $this->driver->diagnostics(); }
            public function cleanupProcessed(int $olderThanSeconds=604800): int { return $this->driver->cleanupProcessed($olderThanSeconds); }
        };
        $service=new AiBackgroundAutomation($db,$failing,$chat);
        $task=$service->create($owner,['title'=>'Recovery','prompt'=>'Background work','interval_minutes'=>60]);
        $pdo->prepare('UPDATE ce_ai_automations SET next_run_at=? WHERE id=?')->execute([time()-1,$task['id']]);
        $result=$service->dispatch();
        self::assertFalse($result['ok']);
        self::assertSame(1,$result['dead_queue_scan_errors']);
        self::assertSame(1,$result['created']);
        self::assertSame(1,$result['offered']);
        self::assertSame(1,$result['backlog']['queued']);
        $job=$queue->pop(); self::assertNotNull($job);
        $service->execute($job->payload['run_id'],new JobContext($job,$queue));
        $queue->complete($job);
        self::assertSame(1,$provider->calls);
    }

    public function testRetentionIsBoundedAndEventuallyClearsAllOldSnapshots(): void
    {
        [$automation,$queue,$pdo,$provider,$owner]=$this->setupEngine();
        $task=$automation->create($owner,['title'=>'Retention','prompt'=>'Sensitive data','interval_minutes'=>60]);
        $insert=$pdo->prepare("INSERT INTO ce_ai_automation_runs(id,automation_id,user_id,prompt_snapshot,scheduled_for,status,queued_at,finished_at) VALUES (?,?,?,?,NULL,'succeeded',?,?)");
        for ($i=0;$i<270;$i++) $insert->execute([Uuid::v4(),$task['id'],$owner,'Sensitive data',time()-32*86400,time()-31*86400]);
        self::assertSame(250,$automation->dispatch()['redacted']);
        self::assertSame(20,$automation->dispatch()['redacted']);
        self::assertSame(0,$automation->dispatch()['redacted']);
        self::assertSame(0,(int)$pdo->query("SELECT COUNT(*) FROM ce_ai_automation_runs WHERE prompt_snapshot='Sensitive data'")->fetchColumn());
    }

}
