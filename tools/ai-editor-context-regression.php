<?php

declare(strict_types=1);

/** Executable even without vendor/ext-pdo_sqlite; validates real CMS field inspection. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CajeerEngine\\')) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) require $file;
    }
});
use CajeerEngine\AI\AiContentEditor;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\AI\OpenAiClient;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
$dir = sys_get_temp_dir().'/ce-ai-context-'.bin2hex(random_bytes(8));mkdir($dir, 0700);
$storage=getenv('CONTENT_STORAGE');putenv('CONTENT_STORAGE=file');
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message."\n";};
$reject=static function(callable $fn,string $message)use($check):void{try{$fn();}catch(\InvalidArgumentException|\DomainException $e){$check(true,$message);return;}throw new RuntimeException('FAIL '.$message);};
try{
    $id='11111111-1111-4111-8111-111111111111';
    (new JsonFile($dir.'/storage/app/content-types.json'))->writeObject(['content_types'=>[['id'=>'22222222-2222-4222-8222-222222222222','handle'=>'pages','name'=>'Pages',
        'fields'=>[['handle'=>'lead','type'=>'textarea'],['handle'=>'blocks','type'=>'blocks']],
        'blocks'=>[['handle'=>'paragraph','fields'=>[['handle'=>'heading','type'=>'text']]]]]]]);
    (new JsonFile($dir.'/storage/app/content/pages.json'))->writeObject(['entries'=>[['id'=>$id,'slug'=>'test','title'=>'Original title','locale'=>'ru','revision'=>3,
        'data'=>['lead'=>'Original lead','blocks'=>[['handle'=>'paragraph','data'=>['heading'=>'Block heading']]]]]]]);
    $db=new DatabaseManager(new ConfigRepository($root));
    $editor=new AiContentEditor($db,new AiSettings($dir),new OpenAiClient(),new ContentEntryRepository($dir,$db),new ContentTypeRepository($dir,$db));
    $method=(new ReflectionClass($editor))->getMethod('context');
    $normal=$method->invoke($editor,'pages',$id,'lead');
    $check($normal['original']==='Original lead'&&$normal['field_type']==='textarea'&&$normal['revision']===3,'real field and revision extracted');
    $block=$method->invoke($editor,'pages',$id,'blocks',0,'heading');
    $check($block['original']==='Block heading'&&$block['field_type']==='text','real nested block text extracted');
    $draft=$method->invoke($editor,'pages',null,'lead');
    $check($draft['entry']===null&&$draft['original']==='','schema-approved unsaved field');
    $reject(fn()=>$method->invoke($editor,'pages',$id,'nonexistent'),'unknown field refused');
    $reject(fn()=>$method->invoke($editor,'pages',$id,'blocks',1,'heading'),'unknown block refused');
    $reject(fn()=>$method->invoke($editor,'pages',$id,'blocks',0,'nonexistent'),'non-text block field refused');
    $reject(fn()=>$method->invoke($editor,'nonexistent',$id,'lead'),'unknown content type refused');
    echo "AI Editor context acceptance PASSED\n";
}finally{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
    putenv('CONTENT_STORAGE='.($storage===false?'':$storage));
}
