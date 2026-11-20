<?php

declare(strict_types=1);

/** Real PDO acceptance for AI SEO proposals, approval, conflicts and accounting. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use($root):void {
    if(str_starts_with($class,'CajeerEngine\\')){
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite', PDO::getAvailableDrivers(),true)){
    if(in_array('--require-db',$argv,true)){fwrite(STDERR,"FAIL PDO SQLite required for AI SEO acceptance\n");exit(1);}
    echo "SKIP PDO SQLite unavailable\n";exit(0);
}
use CajeerEngine\AI\AiContentEditor;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\AI\AiSeoAssistant;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Runtime\ConfigRepository;

$assert=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";};
$reject=static function(callable $fn,string $label)use($assert):void{try{$fn();}catch(Throwable){$assert(true,$label);return;}throw new RuntimeException('FAIL '.$label);};
$dir=sys_get_temp_dir().'/ce-ai-seo-int-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$prior=[];foreach(['CONTENT_STORAGE','CMS_STORAGE','OPENAI_API_KEY'] as $name)$prior[$name]=getenv($name);
try{
    putenv('CONTENT_STORAGE=file');putenv('CMS_STORAGE=file');putenv('OPENAI_API_KEY=sk-test-ai-seo-integration');
    $config=new ConfigRepository($root);$config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);$db->connection()->exec(file_get_contents($root.'/migrations/sqlite/0031_ai_chat.sql'));
    $db->connection()->exec((string)file_get_contents($root.'/migrations/sqlite/0040_ai_cost_control.sql'));
    $db->connection()->exec(file_get_contents($root.'/migrations/sqlite/0032_ai_content_editor.sql'));
    $types=new ContentTypeRepository($dir,$db);$entries=new ContentEntryRepository($dir,$db);
    $types->create(['handle'=>'pages','name'=>'Pages','fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext']]]);
    $initial=$entries->create('pages',['title'=>'Installation guide','slug'=>'installation-guide','status'=>'published','locale'=>'en','data'=>['body'=>'<h1>How to install</h1><p>Verified setup instructions.</p>']]);
    $currentOutput='Installation guide and setup steps';$calls=0;
    $client=new class($currentOutput,$calls) extends OpenAiClient{
        public function __construct(private string &$output,private int &$calls){}
        public function complete(string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null):array{
            $this->calls++;
            if(!str_contains(json_encode($input,JSON_THROW_ON_ERROR),'Actual page content') || !str_starts_with($key,'sk-test-'))throw new RuntimeException('Real page context not supplied');
            return ['text'=>$this->output,'usage'=>['input_tokens'=>24,'output_tokens'=>12],'response_id'=>'resp-mock'];
        }
    };
    $editor=new AiContentEditor($db,new AiSettings($dir),$client,$entries,$types);
    $seo=new AiSeoAssistant($entries,$types,new RouteIndex($dir,$db));
    $uid='11111111-1111-4111-8111-111111111111';$other='22222222-2222-4222-8222-222222222222';
    $audit=$seo->audit('pages',$initial['id']);
    $assert($audit['revision']===(int)$initial['revision'] && in_array('missing_seo_title',array_column($audit['issues'],'code'),true),'audit reads published entry');
    $prop=$editor->propose($uid,['type'=>'pages','entry_id'=>$initial['id'],'field'=>'seo_title','mode'=>'seo_title','instructions'=>'Be precise']);
    $assert($prop['proposed']===$currentOutput && $prop['original']==='','real AI output preview without changing CMS');
    $assert(!array_key_exists('seo_title',$entries->findById('pages',$initial['id'])['data']),'SEO proposal is inert before approval');
    $reject(fn()=>$editor->apply($other,$prop['id'],true),'cross-user proposal access refused');
    $reject(fn()=>$editor->apply($uid,$prop['id']),'published SEO change requires explicit confirmation');
    $applied=$editor->apply($uid,$prop['id'],true);
    $assert($applied['entry']['data']['seo_title']===$currentOutput && $applied['entry']['revision']===(int)$initial['revision']+1,'approved SEO title persists as new CMS revision');
    $assert($seo->audit('pages',$initial['id'])['seo']['title']===$currentOutput,'fresh audit reads newly saved title');
    $reject(fn()=>$editor->apply($uid,$prop['id'],true),'single-use SEO proposal');
    $currentOutput='A complete installation tutorial with verified configuration steps.';
    $desc=$editor->propose($uid,['type'=>'pages','entry_id'=>$initial['id'],'field'=>'meta_description','mode'=>'seo_description','instructions'=>'Short accurate description']);
    $row=$entries->findById('pages',$initial['id']);
    $entries->update('pages',$initial['id'],['title'=>'Manual change','expected_revision'=>$row['revision']]);
    $reject(fn()=>$editor->apply($uid,$desc['id'],true),'stale proposal cannot overwrite newer revision');
    $desc2=$editor->propose($uid,['type'=>'pages','entry_id'=>$initial['id'],'field'=>'meta_description','mode'=>'seo_description','instructions'=>'Short accurate description']);
    $res=$editor->apply($uid,$desc2['id'],true);
    $assert($res['entry']['data']['meta_description']===$currentOutput && $res['entry']['status']==='published','description persisted, publication preserved');
    $reject(fn()=>$editor->propose($uid,['type'=>'pages','entry_id'=>$initial['id'],'field'=>'body','mode'=>'seo_title']),'SEO mode must match safe reserved field');
    $currentOutput=str_repeat('X',200);
    $reject(fn()=>$editor->propose($uid,['type'=>'pages','entry_id'=>$initial['id'],'field'=>'seo_title','mode'=>'seo_title']),'oversized AI title blocked');
    $assert((int)$db->connection()->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn()===0,'AI leases released after errors');
    $row=$db->connection()->query('SELECT used_tokens,reserved_tokens FROM ce_ai_usage')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['used_tokens']>0&&(int)$row['reserved_tokens']===0,'shared AI token budget reconciled');
    $assert($calls>=4,'fake provider actually invoked via OpenAiClient interface');
    echo "AI SEO real PHP/PDO acceptance PASSED\n";
}finally{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
    foreach($prior as $name=>$value)putenv($name.'='.($value===false?'':$value));
}
