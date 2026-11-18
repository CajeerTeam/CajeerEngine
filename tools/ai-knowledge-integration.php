#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real PDO acceptance: documents, visibility, index, CMS synchronization and revision integrity. */
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root.'/core/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) require $file;
});
use CajeerEngine\AI\AiKnowledgeBase;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Content\ContentEntryRepository;

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message."\n";
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (\InvalidArgumentException | \DomainException $e) { $check(true, $message); return; }
    throw new RuntimeException('FAIL '.$message);
};
$terms = AiKnowledgeBase::terms('ПрИвЕт привет HELLO hello echo');
$check(($terms['привет'] ?? 0) === 2 && ($terms['hello'] ?? 0) === 2, 'Cyrillic and Latin tokenization works without provider');
$chunks = AiKnowledgeBase::chunks(str_repeat('Короткое предложение. ', 110), 180);
$check(count($chunks) > 2 && max(array_map('strlen', $chunks)) <= 180, 'Unicode chunker is byte-bounded');
$reject(fn()=>AiKnowledgeBase::terms("broken\xFF"), 'Invalid UTF-8 cannot reach index');
$reject(fn()=>AiKnowledgeBase::chunks('sample', 30), 'Invalid chunk settings fail closed');
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (in_array('--require-db', $argv, true)) { fwrite(STDERR,"FAIL PHP pdo_sqlite required for real knowledge acceptance\n");exit(1); }
    echo "SKIP SQL integration (pdo_sqlite unavailable).\n";
    exit(0);
}
$dir=sys_get_temp_dir().'/cajeer-kb-'.bin2hex(random_bytes(8));mkdir($dir,0700);
foreach (['storage','storage/app','storage/content','config'] as $part) if (!is_dir($dir.'/'.$part)) mkdir($dir.'/'.$part,0700,true);
$old=[];foreach (['CONTENT_STORAGE','CMS_STORAGE'] as $key) { $old[$key]=getenv($key); putenv($key.'=file'); }
try {
    $config = new ConfigRepository($root);
    $config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $db = new DatabaseManager($config); $pdo = $db->connection();
    $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/0034_ai_knowledge_base.sql'));
    $types = new ContentTypeRepository($dir, $db);
    $entries = new ContentEntryRepository($dir, $db);
    $kb = new AiKnowledgeBase($db, $entries, $types);
    $category = $kb->saveCategory(['slug'=>'installation','name'=>'Installation','description'=>'Operator runbooks']);
    $check(count($kb->categories())===1, 'Category persisted in SQLite');
    $public = $kb->saveDocument(['title'=>'Install server','slug'=>'install-server', 'body'=>'Production installation requires backups before deploy and operator audit.',
        'locale'=>'en','visibility'=>'public','status'=>'published','category_id'=>$category['id']]);
    $internal = $kb->saveDocument(['title'=>'Internal recovery','slug'=>'internal-recovery','body'=>'Internal recovery secret operations should remain restricted.',
        'locale'=>'en','visibility'=>'internal','status'=>'published']);
    $draft = $kb->saveDocument(['title'=>'Not published','slug'=>'not-published','body'=>'notvisiblemanualdraft unique',
        'locale'=>'en','visibility'=>'internal','status'=>'draft']);
    $check(count($kb->search('recovery','internal','en'))===1 && $kb->search('recovery','public','en')===[], 'Internal content is excluded from public search');
    $check(count($kb->search('installation','public','en'))===1, 'Published public content is searchable');
    $check($kb->search('notvisiblemanualdraft')===[], 'Draft content is never in retrieval index');
    $check($kb->documents(2,0)['total']===3 && count($kb->documents(2,1)['items'])===2, 'Document pagination is bounded and correct');
    $reject(fn()=> $kb->deleteCategory($category['id']), 'Category cannot be removed while occupied');
    $reject(fn()=> $kb->saveDocument(['title'=>'Race','slug'=>'install-server','body'=>'conflict','expected_revision'=>0],$public['id']), 'Stale editor revision rejected');
    $updated=$kb->saveDocument(['title'=>'Install server','slug'=>'install-server','body'=>'New installation guide with recovery checklist and updates.',
        'locale'=>'en','visibility'=>'public','status'=>'published','category_id'=>$category['id'],'expected_revision'=>1],$public['id']);
    $check((int)$updated['revision']===2 && (int)$updated['indexed_revision']===2, 'Save reindexes same transaction and increments revision');
    $check($kb->search('backups','public','en')===[] && count($kb->search('checklist','public','en'))===1, 'Obsolete search terms removed on change');
    $reject(fn()=>$kb->deleteDocument($public['id'],1), 'Delete requires current revision');
    $kb->deleteDocument($public['id'],2);
    $check($kb->search('checklist')===[], 'Delete removes document and inverted terms');
    $kb->deleteCategory($category['id']);$check($kb->categories()===[], 'Empty category deleted');
    $kb->saveDocument(['title'=>'Not published','slug'=>'not-published','body'=>'draft with new words','locale'=>'en',
        'visibility'=>'internal','status'=>'published','expected_revision'=>1],$draft['id']);
    $check(count($kb->search('draft'))===1, 'Publishing creates index');
    $kb->saveDocument(['title'=>'Not published','slug'=>'not-published','body'=>'draft with new words','locale'=>'en',
        'visibility'=>'internal','status'=>'archived','expected_revision'=>2],$draft['id']);
    $check($kb->search('draft')===[], 'Archiving removes index');
    $file=$kb->importFile(['filename'=>'guide.md','base64'=>base64_encode("# Markdown runbook\n\nInstall disk checkpoints."), 'locale'=>'en']);
    $check($file['source_kind']==='file' && $file['status']==='draft' && $kb->search('checkpoints')===[], 'Markdown import creates unindexed draft');
    $reject(fn()=> $kb->importFile(['filename'=>'hacked.php','base64'=>base64_encode('danger')]), 'Unsupported document type rejected');
    $reject(fn()=> $kb->importFile(['filename'=>'scan.pdf','base64'=>base64_encode('not a pdf')]), 'PDF magic is checked');
    $check($kb->reindex(10)['processed']===3, 'Full reindex rebuilds actual indexed documents');
    $types->create(['handle'=>'pages','name'=>'Pages','localized'=>true,
        'fields'=>[['handle'=>'body','label'=>'Body','type'=>'richtext'],['handle'=>'private_field','label'=>'Private field','type'=>'json']],
        'blocks'=>[]]);
    $cms = $entries->create('pages',['title'=>'Public guide','slug'=>'public-guide','locale'=>'en','status'=>'published',
        'data'=>['body'=>'<p>CMS published knowledge verification.</p>']]);
    $synced=$kb->syncCms('pages',$cms['id']);
    $check($synced['source_kind']==='cms' && (int)$synced['source_revision']===(int)$cms['revision'], 'Published CMS content indexed');
    $check($synced['visibility']==='internal' && count($kb->search('verification','internal','en'))===1 && $kb->search('verification','public','en')===[], 'CMS sync starts internal, preventing unreviewed publication');
    $promoted=$kb->saveDocument(['title'=>$synced['title'],'slug'=>$synced['slug'],'body'=>$synced['body'],'locale'=>$synced['locale'],
        'visibility'=>'public','status'=>'published','expected_revision'=>1],$synced['id']);
    $check(count($kb->search('verification','public','en'))===1, 'Reviewed CMS document can be exposed publicly');
    $again=$kb->syncCms('pages',$cms['id']);
    $check($again['id']===$synced['id'] && (int)$again['revision']===3 && $again['visibility']==='public', 'Repeated CMS sync preserves reviewed visibility and reindexes same document');
    $reject(fn()=> $kb->saveDocument(['title'=>'Write CMS','slug'=>'write-cms','body'=>'override','expected_revision'=>3],$again['id']), 'CMS linked document cannot be edited by hand');
    $entries->update('pages',$cms['id'],['title'=>'Public guide updated','expected_revision'=>$cms['revision']]);
    $check($kb->search('verification','public')===[], 'Changed source is hidden until explicit resynchronization');
    $repaired=$kb->syncCms('pages',$cms['id']);
    $check($repaired['id']===$synced['id'] && count($kb->search('verification','public'))===1, 'Resync makes latest source visible again');
    $kb->deleteDocument($repaired['id'],(int)$repaired['revision']);
    $check($pdo->query('SELECT COUNT(*) FROM ce_kb_terms')->fetchColumn()>0, 'Other indexed documents survive selective delete');
    echo "AI Knowledge Base real PDO acceptance PASSED\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if($file->isDir() && !$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());
    }rmdir($dir);
    foreach($old as $name=>$value)putenv($name.'='.($value===false?'':$value));
}
