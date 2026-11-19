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
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

final class TestAutomationAiProvider extends OpenAiClient
{
    public int $calls = 0;

    public function complete(string $key, string $model, array $input, int $maxOutput, ?callable $onDelta = null): array
    {
        ++$this->calls;
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
        foreach (['0001_initial_schema','0031_ai_chat','0037_ai_tools','0040_ai_cost_control','0041_ai_background_automation'] as $name) {
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
        return [new AiBackgroundAutomation($db,$queue,$chat),$queue,$pdo,$provider,$user,$other];
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
}
