#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real database + CMS + support acceptance for 1.7.2 approved AI actions. */
$root = dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (!str_starts_with($class,'CajeerEngine\\')) return;
    $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
    if (is_file($file)) require_once $file;
});
if (!in_array('sqlite', PDO::getAvailableDrivers(),true)) {
    if (in_array('--require-db',$argv,true)) throw new RuntimeException('pdo_sqlite is required for AI Actions acceptance');
    echo "SKIP pdo_sqlite unavailable\n"; exit(0);
}
use CajeerEngine\AI\AiActions;
use CajeerEngine\AI\AiSupportCenter;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

function pass172(bool $valid, string $what): void { if (!$valid) throw new RuntimeException('FAIL: '.$what); echo 'PASS '.$what."\n"; }
function denied172(callable $run, string $what):void {
    try { $run(); } catch (DomainException|InvalidArgumentException|RuntimeException $e) { pass172(true,$what);return; }
    throw new RuntimeException('FAIL: '.$what);
}
$tmp=sys_get_temp_dir().'/ce-actions-'.bin2hex(random_bytes(5));mkdir($tmp,0700);
$old=getenv('CONTENT_STORAGE');
try {
    putenv('CONTENT_STORAGE=database');
    $config=new ConfigRepository($root);$config->set('database.default','sqlite');
    $config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);$pdo=$db->connection();
    foreach (['0001_initial_schema','0002_database_core','0003_content_core','0010_public_routes','0012_cms_search','0036_ai_support_center','0038_ai_actions'] as $migration) {
        $pdo->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
    }
    $types=new ContentTypeRepository($tmp,$db);$entries=new ContentEntryRepository($tmp,$db);
    $types->create(['handle'=>'pages','name'=>'Pages','fields'=>[['handle'=>'body','label'=>'Body','type'=>'textarea']]]);
    $support=(new ReflectionClass(AiSupportCenter::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(AiSupportCenter::class,'db'))->setValue($support,$db);
    $actions=new AiActions($db,$types,$entries,$support,new AuditLogger($tmp));
    $user='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$other='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $write=['content:read','content:write'];
    denied172(fn()=>$actions->prepare('cms_action_create_draft', ['type'=>'pages','title'=>'New','slug'=>'new','locale'=>'ru','content_field'=>'body','content'=>'Body'],$user,['content:read']), 'read-only sessions cannot prepare actions');
    denied172(fn()=>$actions->prepare('cms_action_create_draft', ['type'=>'pages','title'=>'New','slug'=>'new','locale'=>'ru','content_field'=>'secret','content'=>'Body'],$user,$write), 'unknown fields rejected before saving');
    $draft=$actions->prepare('cms_action_create_draft',['type'=>'pages','title'=>'New page','slug'=>'new-page','locale'=>'ru','content_field'=>'body','content'=>'Real generated text'],$user,$write);
    pass172($draft['state']==='pending' && $entries->page('pages',['status'=>'all'],10,0)['meta']['total']===0,'prepare does not create CMS content');
    denied172(fn()=>$actions->approve($other,$draft['id'],$write,false),'cross-user approval denied');
    denied172(fn()=>$actions->approve($user,$draft['id'],['content:read'],false),'revoked write permission denies approval');
    $created=$actions->approve($user,$draft['id'],$write,false);
    pass172($created['state']==='applied' && $created['result']['status']==='draft','confirmed action creates actual draft');
    $record=$entries->findById('pages',$created['result']['id']);
    pass172($record['data']['body']==='Real generated text' && $record['status']==='draft','draft contains validated content');
    denied172(fn()=>$actions->approve($user,$draft['id'],$write,false),'duplicate approval denied');
    $pendingSeo=$actions->prepare('cms_action_update_seo',['type'=>'pages','id'=>$record['id'],'field'=>'seo_title','value'=>'SEO replacement'],$user,$write);
    pass172((($entries->findById('pages', $record['id'])['data']['seo_title'] ?? null) === null), 'SEO only proposed, not applied');
    $seo=$actions->approve($user,$pendingSeo['id'],$write,false);
    pass172($seo['result']['revision']===2 && $entries->findById('pages',$record['id'])['data']['seo_title']==='SEO replacement','SEO approval creates revision');
    $stale=$actions->prepare('cms_action_update_seo',['type'=>'pages','id'=>$record['id'],'field'=>'seo_title','value'=>'Old suggestion'],$user,$write);
    $fresh=$entries->findById('pages',$record['id']);
    $entries->update('pages',$record['id'],['expected_revision'=>$fresh['revision'],'data'=>array_replace($fresh['data'],['seo_title'=>'Human edit'])]);
    denied172(fn()=>$actions->approve($user,$stale['id'],$write,false),'stale revision blocked');
    pass172($actions->get($user,$stale['id'])['state']==='pending','failed approval leaves proposal reviewable');
    $published=$entries->publish('pages',$record['id']);
    $pub=$actions->prepare('cms_action_update_seo',['type'=>'pages','id'=>$record['id'],'field'=>'meta_description','value'=>'Verified description'],$user,$write);
    denied172(fn()=>$actions->approve($user,$pub['id'],$write,false),'published material requires explicit second confirmation');
    pass172($actions->approve($user,$pub['id'],$write,true)['state']==='applied','published SEO change requires second confirmation');
    $toReject=$actions->prepare('cms_action_update_seo',['type'=>'pages','id'=>$record['id'],'field'=>'seo_title','value'=>'Rejected'],$user,$write);
    pass172($actions->reject($user,$toReject['id'])['state']==='rejected','operator can reject AI proposal');
    denied172(fn()=>$actions->approve($user,$toReject['id'],$write,false),'rejected action never executes');
    $ticket='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $pdo->prepare('INSERT INTO ce_support_tickets(id,access_hash,name,email,subject,locale,status,priority,needs_human,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$ticket,str_repeat('a',64),'Client','client@example.test','Question','ru','open','normal',1,1,time(),time()]);
    $ticketAction=$actions->prepare('cms_action_support_status',['ticket_id'=>$ticket,'status'=>'resolved'],$user,$write);
    pass172($support->agentView($ticket)['status']==='open','support status is not changed by model proposal');
    $closed=$actions->approve($user,$ticketAction['id'],$write,false);
    pass172($closed['result']['status']==='resolved' && $support->agentView($ticket)['revision']===2,'operator approval updates real support ticket using revision CAS');
    $race=$actions->prepare('cms_action_support_status',['ticket_id'=>$ticket,'status'=>'closed'],$user,$write);
    $support->update($ticket,$user,['expected_revision'=>2,'status'=>'waiting']);
    denied172(fn()=>$actions->approve($user,$race['id'],$write,false),'stale ticket status cannot overwrite another operator');
    $leak=json_encode($actions->pending($other),JSON_THROW_ON_ERROR);
    pass172($leak==='[]','other users cannot list private pending actions');
    echo "AI Actions real PHP/PDO acceptance PASSED\n";
} finally {
    putenv('CONTENT_STORAGE='.($old===false?'':$old));
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        if ($f->isDir()&&!$f->isLink())rmdir($f->getPathname()); else unlink($f->getPathname());
    } rmdir($tmp);
}
