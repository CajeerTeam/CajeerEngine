<?php

declare(strict_types=1);
/** End-to-end translation: real SQL budgets/proposals, file CMS revisions, deterministic fake OpenAI. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    if(in_array('--require-db',$argv,true)){fwrite(STDERR,"FAIL PDO SQLite required\n");exit(1);}
    echo "SKIP PDO SQLite unavailable\n";exit(0);
}
use CajeerEngine\AI\AiTranslationStudio;
use CajeerEngine\AI\AiProviderException;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Queue\FileQueueDriver;
use CajeerEngine\Runtime\ConfigRepository;
$assert=static function(bool $value,string $name):void{if(!$value)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";};
$reject=static function(callable $fn,string $name)use($assert):void{try{$fn();}catch(Throwable){$assert(true,$name);return;}throw new RuntimeException('FAIL '.$name);};
$dir=sys_get_temp_dir().'/ce-ai-translation-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$old=[];foreach(['OPENAI_API_KEY','CONTENT_STORAGE','CMS_STORAGE'] as $name)$old[$name]=getenv($name);
try{
    putenv('OPENAI_API_KEY=sk-test-ai-translation-service');putenv('CONTENT_STORAGE=file');putenv('CMS_STORAGE=file');
    $config=new ConfigRepository($root);$config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);$db->connection()->exec(file_get_contents($root.'/migrations/sqlite/0031_ai_chat.sql'));
    $db->connection()->exec(file_get_contents($root.'/migrations/sqlite/0032_ai_content_editor.sql'));
    $db->connection()->exec(file_get_contents($root.'/migrations/sqlite/0033_ai_translation_studio.sql'));
    $types=new ContentTypeRepository($dir,$db);$entries=new ContentEntryRepository($dir,$db);
    $types->create(['handle'=>'pages','name'=>'Pages','localized'=>true,'fields'=>[
      ['handle'=>'body','label'=>'Body','type'=>'richtext'],['handle'=>'intro','label'=>'Intro','type'=>'textarea'],
      ['handle'=>'blocks','label'=>'Blocks','type'=>'blocks'],['handle'=>'published_link','label'=>'Link','type'=>'text']
    ],'blocks'=>[['handle'=>'paragraph','name'=>'Paragraph','fields'=>[['handle'=>'heading','label'=>'Heading','type'=>'text']]]]]);
    $source=$entries->create('pages',['title'=>'Hello friend','slug'=>'hello','locale'=>'en','status'=>'published',
     'data'=>['body'=>'<p>Hello <a href="https://example.com">friend</a>, {{name}} &amp; %s!</p>',
              'intro'=>'Hello world', 'published_link'=>'Click https://example.com now',
              'blocks'=>[['handle'=>'paragraph','data'=>['heading'=>'Heading']]],
              'canonical_url'=>'https://example.com/en/hello']]);
    $calls=0;$broken=false;
    $client=new class($calls,$broken) extends OpenAiClient{
        public function __construct(private int &$calls,private bool &$broken){}
        public function complete(string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null):array{
            ++$this->calls;
            if($this->broken)throw new AiProviderException('Unavailable',503);
            if(!str_starts_with($key,'sk-test-'))throw new RuntimeException('Missing server-side API key');
            $body=json_decode($input[1]['content'],true,512,JSON_THROW_ON_ERROR);
            if(!isset($body['glossary'],$body['segments'])||$body['source_locale']!=='en'||$body['target_locale']!=='de')throw new RuntimeException('Missing localization context');
            $out=[];foreach($body['segments'] as $name=>$value)$out[$name]='DE-'.$value;
            return ['text'=>json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                'usage'=>['input_tokens'=>120,'output_tokens'=>80],'response_id'=>'resp-test'];
        }
    };
    $service=new AiTranslationStudio($db,new AiSettings($dir),$client,$entries,$types);
    $uid='11111111-1111-4111-8111-111111111111';$other='22222222-2222-4222-8222-222222222222';
    $terms=$service->saveTerm('pages','en','de','World','Welt');
    $assert(count($terms)===1 && $terms[0]['translated_term']==='Welt','Glossary persisted in real SQL');
    $proposal=$service->propose($uid,['type'=>'pages','entry_id'=>$source['id'],'target_locale'=>'de','update_existing'=>false,'instructions'=>'Formal style']);
    $assert($proposal['segment_count']>=5 && str_starts_with($proposal['translated_title'],'DE-'),'Provider translated real title, blocks and text');
    $assert($entries->localizations('pages',$source['id'])[0]['locale']==='en','Proposal does not mutate published source');
    $reject(fn()=>$service->apply($other,$proposal['id']),'Cannot approve another user proposal');
    $result=$service->apply($uid,$proposal['id']);
    $assert($result['created'] && $result['entry']['status']==='draft' && $result['entry']['locale']==='de','Approved proposal creates real draft localization');
    $assert($result['entry']['translation_group']===$source['translation_group'],'Translation stays in source group');
    $assert(str_contains($result['entry']['data']['body'],'<a href="https://example.com">') && str_contains($result['entry']['data']['body'],'{{name}}') && str_contains($result['entry']['data']['body'],'&amp;') && str_contains($result['entry']['data']['body'],'%s'),'HTML and all protected tokens unchanged');
    $assert(str_starts_with($result['entry']['data']['blocks'][0]['data']['heading'],'DE-'),'Nested block translation persisted');
    $assert(($result['entry']['data']['canonical_url']??'')==='','Canonical URL never copied between languages');
    $overview=$service->overview('pages',$source['id']);
    $match=array_values(array_filter($overview['localizations'],static fn($r)=>$r['locale']==='de'))[0];
    $assert($match['ai_generated']===true && $match['stale']===false,'Fresh translation tracked');
    $reject(fn()=>$service->apply($uid,$proposal['id']),'A proposal cannot create the same locale twice');
    $reject(fn()=>$service->propose($uid,['type'=>'pages','entry_id'=>$source['id'],'target_locale'=>'de','update_existing'=>false]),'Explicit update required for existing locale');
    $update=$service->propose($uid,['type'=>'pages','entry_id'=>$source['id'],'target_locale'=>'de','update_existing'=>true]);
    $fresh=$entries->findById('pages',$result['entry']['id']);
    $entries->update('pages',$fresh['id'],['title'=>'Human edit','expected_revision'=>$fresh['revision']]);
    $reject(fn()=>$service->apply($uid,$update['id']),'Stale target revision cannot be overwritten');
    $again=$service->propose($uid,['type'=>'pages','entry_id'=>$source['id'],'target_locale'=>'de','update_existing'=>true]);
    $approved=$service->apply($uid,$again['id']);
    $assert(!$approved['created'] && $approved['entry']['revision']===(int)$fresh['revision']+2,'Existing translation safely updated as new revision');
    $published=$entries->publish('pages',$approved['entry']['id']);
    $pubProposal=$service->propose($uid,['type'=>'pages','entry_id'=>$source['id'],'target_locale'=>'de','update_existing'=>true]);
    $reject(fn()=>$service->apply($uid,$pubProposal['id']),'Published translation requires explicit confirmation');
    $done=$service->apply($uid,$pubProposal['id'],true);
    $assert($done['entry']['status']==='published','Confirming a published update preserves publication state');
    $current=$entries->findById('pages',$source['id']);
    $entries->update('pages',$source['id'],['title'=>'Source revision changed','expected_revision'=>$current['revision']]);
    $overview=$service->overview('pages',$source['id']);
    $match=array_values(array_filter($overview['localizations'],static fn($r)=>$r['locale']==='de'))[0];
    $assert($match['stale']===true,'AI translation marked stale after source is edited');
    $reject(fn()=>$service->apply($uid,$pubProposal['id'],true),'Already approved proposal is single-use');
    // Actual durable queue item, not a simulated endpoint: worker resumes and
    // restores the persisted proposal without re-charging or re-calling OpenAI.
    $queue = new FileQueueDriver($dir);
    $batch = $service->enqueueBatch($uid, ['type'=>'pages','entry_ids'=>[$source['id']], 'target_locale'=>'de','update_existing'=>true], $queue);
    $assert($batch['total']===1 && $batch['ready']===0 && $batch['items'][0]['status']==='queued','Batch persisted and queued');
    $job = $queue->pop();
    $assert($job !== null && $job->name==='ai.translation.propose' && $job->attempts===1,'Worker receives actual durable queue item');
    $service->runBatchItem($job->payload['item_id'], $job->attempts, $job->maxAttempts);
    $queue->complete($job);
    $batch = $service->batchStatus($uid, $batch['id']);
    $assert($batch['ready']===1 && $batch['items'][0]['proposal_id']!==null,'Completed queued translation is available for editorial review');
    $loaded = $service->proposal($uid, $batch['items'][0]['proposal_id']);
    $assert($loaded['target_locale']==='de' && $loaded['target_entry_id']===$result['entry']['id'],'Queue result resumes real existing-localization workflow');
    $reject(fn()=>$service->batchStatus($other, $batch['id']),'Other user cannot access a batch');
    $reject(fn()=>$service->proposal($other, $loaded['id']),'Other user cannot see a queued proposal');
    $before=$calls;
    $service->runBatchItem($job->payload['item_id'], 2, $job->maxAttempts);
    $assert($calls===$before,'Redelivery does not double-charge or call the provider again');
    $service->deleteTerm($terms[0]['id']);
    $assert($service->glossary('pages','en','de')===[],'Glossary term actually deleted');
    $assert((int)$db->connection()->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn()===0,'No leaked generation lease');
    $usage=$db->connection()->query('SELECT used_tokens,reserved_tokens FROM ce_ai_usage')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$usage['used_tokens']>0 && (int)$usage['reserved_tokens']===0,'Shared token budget settled');
    $assert($calls>=4,'Real provider invocation path exercised');
    echo "AI Translation real PDO/CMS acceptance PASSED\n";
}finally{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
    foreach($old as $name=>$value)putenv($name.'='.($value===false?'':$value));
}
