#!/usr/bin/env php
<?php

declare(strict_types=1);

/** AI Knowledge Chat 1.6.7 real SQLite, deterministic provider and revocation acceptance. */
$root=dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class,'CajeerEngine\\')) return;
    $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
    if (is_file($file)) require $file;
});
use CajeerEngine\AI\AiKnowledgeChat;
use CajeerEngine\AI\AiKnowledgeBase;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$check=static function(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS '.$label."\n";};
$reject=static function(callable $run,string $label) use($check): void {
    try{$run();}catch(InvalidArgumentException|DomainException|AiProviderException $e){$check(true,$label);return;}
    throw new RuntimeException('FAIL: '.$label);
};
$ids=[['id'=>'11111111-1111-4111-8111-111111111111','title'=>'Public','revision'=>1,'locale'=>'ru','visibility'=>'public','chunk_no'=>0],
      ['id'=>'22222222-2222-4222-8222-222222222222','title'=>'Internal','revision'=>1,'locale'=>'ru','visibility'=>'internal','chunk_no'=>1]];
$a=AiKnowledgeChat::groundedAnswer('Подтверждено [[S2]], но [[S99]] неизвестен.',$ids);
$check($a['text']==='Подтверждено [2], но  неизвестен.' && count($a['sources'])===1 && $a['sources'][0]['id']===$ids[1]['id'],'Only explicitly cited offered source is returned');
$b=AiKnowledgeChat::groundedAnswer('Необоснованное утверждение', $ids);
$check($b['sources']===[] && str_contains($b['text'],'Не удалось'),'Uncited LLM answer is not released');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    if(in_array('--require-db',$argv,true)){fwrite(STDERR,"FAIL pdo_sqlite required for RAG integration\n");exit(1);}
    echo "SKIP SQL integration: pdo_sqlite unavailable.\n";exit(0);
}
$dir=sys_get_temp_dir().'/ce-rag-'.bin2hex(random_bytes(7));mkdir($dir,0700,true);
$env=[];
foreach(['INTEGRATIONS_KEY','OPENAI_API_KEY','CONTENT_STORAGE'] as $name)$env[$name]=getenv($name);
try{
    putenv('INTEGRATIONS_KEY=base64:'.base64_encode(random_bytes(32)));
    putenv('OPENAI_API_KEY=');putenv('CONTENT_STORAGE=file');
    $settings=new AiSettings($dir);
    $settings->update(['api_key'=>'sk-test-123456789012345678901234567890', 'model'=>'gpt-4.1-mini',
        'max_output_tokens'=>256,'monthly_token_limit'=>40000]);
    $config=new ConfigRepository($root);
    $config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);$pdo=$db->connection();
    foreach(['0031_ai_chat','0032_ai_content_editor','0034_ai_knowledge_base','0035_ai_knowledge_chat'] as $migration)
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
    $entries=new ContentEntryRepository($dir,$db);$types=new ContentTypeRepository($dir,$db);
    $kb=new AiKnowledgeBase($db,$entries,$types);
    $public=$kb->saveDocument(['title'=>'Руководство по установке','slug'=>'rag-setup','body'=>'Для установки выполните резервное копирование и проверьте PHP. Установка требует резервной копии.',
        'locale'=>'ru','visibility'=>'public','status'=>'published']);
    $private=$kb->saveDocument(['title'=>'Внутреннее восстановление','slug'=>'rag-recovery','body'=>'Внутреннее восстановление использует процедуру резервирования аварийного хранилища.',
        'locale'=>'ru','visibility'=>'internal','status'=>'published']);
    $draft=$kb->saveDocument(['title'=>'Секретный черновик','slug'=>'rag-draft','body'=>'Уникальная фраза черновиксекрет запущенная задача.',
        'locale'=>'ru','visibility'=>'internal','status'=>'draft']);
    $check(count($kb->retrieve('установка', 'public','ru'))>=1,'Live retrieval returns published indexed chunks');
    $check($kb->retrieve('восстановление','public','ru')===[],'Internal chunks are never searched in public mode');
    $check($kb->retrieve('черновиксекрет','all','ru')===[],'Draft text cannot enter context');
    $seen=[];$fail=false;$onCall=null;
    $provider=new class($seen,$fail,$onCall) extends OpenAiClient {
        public function __construct(private array &$seen,private bool &$fail, private mixed &$onCall){}
        public function complete(string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null): array {
            if($this->fail){$this->fail=false;throw new AiProviderException('Fixture OpenAI failure',503);}
            $this->seen[]=$input;
            if(!str_starts_with($key,'sk-test-'))throw new RuntimeException('Missing credential');
            if(is_callable($this->onCall))($this->onCall)();
            return ['text'=>'Ответ основан на документах [[S1]].','usage'=>['input_tokens'=>37,'output_tokens'=>19],'response_id'=>'resp-rag-test'];
        }
    };
    $rag=new AiKnowledgeChat($db,$kb,$settings,$provider);
    $owner='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$other='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $no=$rag->ask($owner,null,'черновиксекрет','all','ru');
    $check($no['sources']===[] && count($seen)===0,'No matching source means no provider invocation or tokens');
    $answer=$rag->ask($owner,null,'установка','public','ru');
    $check(count($answer['sources'])===1 && $answer['sources'][0]['id']===$public['id'],'Grounded public response cites only public documents');
    $check(!str_contains(json_encode($seen),'аварийного') && !str_contains(json_encode($seen),'черновиксекрет'),
        'Provider receives neither internal nor draft context in public mode');
    $history=$rag->messages($owner,$answer['conversation_id']);
    $check(count($history)===2 && $history[1]['content']==='Ответ основан на документах [1].','Actual SQL conversation and messages persisted');
    $check(count($rag->conversations($owner))===2 && count($rag->conversations($other))===0,'Conversations are owner-scoped');
    $reject(fn()=>$rag->messages($other,$answer['conversation_id']),'Cross-user history forbidden');
    $reject(fn()=>$rag->ask($other,$answer['conversation_id'],'установка','public','ru'),'Cross-user continuation forbidden');
    $reject(fn()=>$rag->ask($owner,$answer['conversation_id'],'установка','all','ru'),'Cannot silently broaden a conversation visibility');
    $reject(fn()=>$rag->feedback($other,$answer['message_id'],1),'Only owner can rate answer');
    $check($rag->feedback($owner,$answer['message_id'],1)['feedback']===1,'Owner feedback is stored');
    $internal=$rag->ask($owner,null,'аварийного','all','ru');
    $check(count($internal['sources'])===1 && $internal['sources'][0]['id']===$private['id'],'Authorized internal query retrieves internal document');
    $kb->saveDocument(['title'=>$public['title'],'slug'=>$public['slug'],'body'=>'Документ обновлён. Другой процесс.',
        'locale'=>'ru','visibility'=>'public','status'=>'published','expected_revision'=>(int)$public['revision']],$public['id']);
    $redacted=$rag->messages($owner,$answer['conversation_id']);
    $check($redacted[1]['revoked']===true && $redacted[1]['sources']===[] && !str_contains($redacted[1]['content'],'Ответ основан'),
        'Historical answers are redacted after source revision changes');
    $fail=true;
    $reject(fn()=>$rag->ask($owner,null,'аварийного','all','ru'),'Provider failure surfaced and cannot commit answer');
    $check((int)$pdo->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn()===0,'Provider failure releases shared token lease');
    $before=(int)$pdo->query('SELECT SUM(used_tokens) FROM ce_ai_usage')->fetchColumn();
    $onCall=static function()use($kb,$private): void {
        $kb->saveDocument(['title'=>$private['title'],'slug'=>$private['slug'],'body'=>'Заменённый текст, старый ответ недействителен.',
            'locale'=>'ru','visibility'=>'internal','status'=>'published','expected_revision'=>(int)$private['revision']],$private['id']);
    };
    $race=$rag->ask($owner,null,'аварийного','all','ru');
    $check($race['sources']===[] && str_contains($race['text'],'изменился'),'Concurrent document revision blocks ungrounded answer');
    $check((int)$pdo->query('SELECT SUM(used_tokens) FROM ce_ai_usage')->fetchColumn()>$before,'Provider usage is charged even if source changes mid-generation');
    $check((int)$pdo->query('SELECT SUM(reserved_tokens) FROM ce_ai_usage')->fetchColumn()===0,'No leaked reserved tokens');
    $pdo->prepare('INSERT INTO ce_ai_editor_jobs (id,user_id,period,reserved_tokens,expires_at) VALUES (?,?,?,?,?)')
        ->execute(['dddddddd-dddd-4ddd-8ddd-dddddddddddd',$owner,gmdate('Y-m'),100,time()+180]);
    $reject(fn()=>$rag->remove($owner,$answer['conversation_id']),'In-flight RAG generation blocks history deletion');
    $pdo->prepare('DELETE FROM ce_ai_editor_jobs WHERE user_id = ?')->execute([$owner]);
    $rag->remove($owner,$answer['conversation_id']);
    $check(count($rag->conversations($owner))===3 && (int)$pdo->query('SELECT COUNT(*) FROM ce_ai_kb_messages WHERE role = "assistant"')->fetchColumn()===3,'Deleting a conversation removes its history only');
    $reject(fn()=>$rag->remove($other,$internal['conversation_id']),'Cross-user deletion forbidden');
    echo "AI Knowledge Chat RAG SQL acceptance PASSED\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){
        if($f->isDir() && !$f->isLink()) rmdir($f->getPathname()); else unlink($f->getPathname());
    }
    rmdir($dir);
    foreach($env as $name=>$value)putenv($name.'='.($value===false?'':$value));
}
