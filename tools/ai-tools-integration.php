<?php

declare(strict_types=1);

/** Real PDO/SQLite + encrypted key + Knowledge Base index + AI Chat function-call acceptance. */
$root = dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class,'CajeerEngine\\')) {
        $file = $root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if (is_file($file)) require $file;
    }
});
if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
    if (in_array('--require-db',$argv,true)) { fwrite(STDERR,"FAIL pdo_sqlite required\n"); exit(1); }
    echo "SKIP pdo_sqlite unavailable\n";
    exit(0);
}
use CajeerEngine\AI\{AiChat,AiKnowledgeBase,AiTools,AiSettings,OpenAiClient};
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
function verify171(bool $result,string $what):void{if(!$result)throw new RuntimeException('FAIL '.$what);echo 'PASS '.$what."\n";}
$workspace=sys_get_temp_dir().'/ce-ai-tools-'.bin2hex(random_bytes(6));
mkdir($workspace,0700);
try {
    putenv('INTEGRATIONS_KEY=base64:'.base64_encode(random_bytes(32)));
    putenv('OPENAI_API_KEY=sk-test-this-is-not-a-live-credential');
    $config=new ConfigRepository($root);
    $config->set('database.default','sqlite');
    $config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);
    $pdo=$db->connection();$pdo->exec('PRAGMA foreign_keys = ON');
    foreach (['0031_ai_chat','0034_ai_knowledge_base','0037_ai_tools','0040_ai_cost_control'] as $migration)
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
    $types=new ContentTypeRepository($workspace,$db);
    $entries=new ContentEntryRepository($workspace,$db);
    $knowledge=new AiKnowledgeBase($db,$entries,$types);
    $doc=$knowledge->saveDocument(['title'=>'Deployment manual','slug'=>'deployment-manual','body'=>'Deploy CajeerEngine using verified release archives. Run database migrations before activating new workers.','locale'=>'ru','visibility'=>'internal','status'=>'published']);
    verify171(count($knowledge->search('deploy','internal','ru',5))===1,'real Knowledge Base index returns internal document');
    // Supply only dependencies used by the tested read-only tool; every execution
    // still passes production argument, RBAC, output safety and AuditLogger logic.
    $tools=(new ReflectionClass(AiTools::class))->newInstanceWithoutConstructor();
    foreach (['knowledge'=>$knowledge,'audit'=>new AuditLogger($workspace)] as $property=>$value)
        (new ReflectionProperty(AiTools::class,$property))->setValue($tools,$value);
    $provider=new class extends OpenAiClient {
        public int $step=0;
        public array $seen=[];
        protected function request(string $key,string $model,array $input,int $maxOutput,array $tools,?callable $onDelta=null,bool $forceText=false):array {
            $this->seen[]=$input;
            if($this->step++===0)return ['text'=>'','calls'=>[['type'=>'function_call','name'=>'cms_knowledge_search','call_id'=>'call_knowledge_001','arguments'=>json_encode(['query'=>'deploy','visibility'=>'internal','locale'=>'ru','limit'=>5])]],'usage'=>['input_tokens'=>44,'output_tokens'=>12],'response_id'=>'resp_1'];
            verify171(str_contains(json_encode($input,JSON_UNESCAPED_UNICODE),'Deployment manual'),'actual indexed document sent to model');
            return ['text'=>'Документ о развёртывании найден.','calls'=>[],'usage'=>['input_tokens'=>60,'output_tokens'=>18],'response_id'=>'resp_2'];
        }
    };
    $settings=new AiSettings($workspace);
    $chat=new AiChat($db,$settings,$provider,$tools);
    $owner='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $trace=[];
    $answer=$chat->send($owner,null,'Найди инструкцию deploy',null,true,['content:read'],function(array $tool)use(&$trace):void{$trace[]=$tool;});
    verify171($answer['text']==='Документ о развёртывании найден.' && count($answer['tools'])===1, 'real AI Chat saves tool-assisted model answer');
    verify171($answer['usage']===['input_tokens'=>104,'output_tokens'=>30,'cached_input_tokens'=>0],'multi-round token accounting committed');
    $history=$chat->messages($owner,$answer['conversation_id']);
    verify171(count($history)===2 && count($history[1]['tools'])===1,'persisted tool names and status displayed in history');
    verify171(count($trace)===1 && $trace[0]['name']==='cms_knowledge_search','SSE callback receives tool trace');
    verify171((int)$pdo->query('SELECT reserved_tokens FROM ce_ai_usage')->fetchColumn()===0,'budget reservation released after completion');
    verify171((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_tool_calls')->fetchColumn()===1,'tool audit trace committed');
    $log=(string)file_get_contents($workspace.'/storage/logs/audit.jsonl');
    verify171(!str_contains($log,'Deployment manual'),'audit event does not store document contents');
    $chat->delete($owner,$answer['conversation_id']);
    verify171((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_tool_calls')->fetchColumn()===0,'deleting conversation cascades tool audit trace');
    echo "AI Tools PDO acceptance PASSED\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname()); }
    rmdir($workspace);
}
