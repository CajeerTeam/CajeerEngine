#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Real SQL acceptance of migration remediation proposals, CAS, rollback and route verification. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class,'CajeerEngine\\')) {
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if (is_file($file)) require_once $file;
    }
});
if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
    if (in_array('--require-db',$argv,true)) throw new RuntimeException('pdo_sqlite is required for migration remediation acceptance.');
    echo "SKIP pdo_sqlite unavailable\n"; exit(0);
}
use CajeerEngine\AI\AiMigrationRemediation;
use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Content\{ContentEntryRepository,ContentTypeRepository};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Migration\MigrationCenter;
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Runtime\ConfigRepository;

function check176(bool $value,string $reason): void {if(!$value)throw new RuntimeException('FAIL '.$reason);echo "PASS $reason\n";}
function denied176(callable $callback,string $reason):void {
    try{$callback();}catch(DomainException|InvalidArgumentException|RuntimeException $e){check176(true,$reason);return;}
    throw new RuntimeException('FAIL expected rejection: '.$reason);
}
$work=sys_get_temp_dir().'/ce-remediation-'.bin2hex(random_bytes(6));mkdir($work,0700);
$prior=getenv('CONTENT_STORAGE');$priorUrl=getenv('APP_URL');
try {
    putenv('CONTENT_STORAGE=database');putenv('APP_URL=https://example.test');
    mkdir($work.'/config');foreach(glob($root.'/config/*.php') as $source)copy($source,$work.'/config/'.basename($source));
    mkdir($work.'/storage',0700);mkdir($work.'/storage/app',0700);mkdir($work.'/storage/app/migration',0700);
    $config=new ConfigRepository($root);$config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);$pdo=$db->connection();
    foreach(glob($root.'/migrations/sqlite/*.sql') as $migration)$pdo->exec((string)file_get_contents($migration));
    $types=new ContentTypeRepository($work,$db);$entries=new ContentEntryRepository($work,$db);
    $types->create(['handle'=>'pages','name'=>'Pages','fields'=>[['handle'=>'body','label'=>'Body','type'=>'textarea']]]);
    $page=$entries->create('pages',['title'=>'Target page','slug'=>'target-article','status'=>'published','locale'=>'ru','data'=>['body'=>'Real content']]);
    $routes=new RouteIndex($work,$db);$route=$routes->forEntry($page['id']);
    check176(is_array($route)&&($route['kind']??null)==='page','actual target page is published and indexed');
    $project=str_repeat('a',32);$user='cccccccc-cccc-4ccc-8ccc-cccccccccccc';$other='dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    file_put_contents($work.'/storage/app/migration/'.$project.'.json',json_encode(['id'=>$project,'name'=>'Completed source','source'=>'wordpress-wxr','status'=>'completed','revision'=>4,'created_at'=>gmdate(DATE_ATOM),'updated_at'=>gmdate(DATE_ATOM)]));
    $pdo->prepare('INSERT INTO ce_migration_seo_path_evidence(project_id,source_path,target_path) VALUES (?,?,?)')->execute([$project,'/old-article',$route['path']]);
    $center=new MigrationCenter($work,new SecretVault('base64:'.base64_encode(random_bytes(32))));
    $service=new AiMigrationRemediation($work,$db,$center,new AuditLogger($work));
    $options=$service->candidates($project);
    check176(count($options['candidates'])===1&&$options['candidates'][0]['kind']==='restore_redirect','missing redirect discovered from actual SQL evidence');
    denied176(fn()=>$service->prepare($project,$user,'restore_redirect','/unverified'), 'unapproved source mapping rejected');
    denied176(fn()=>$service->prepare($project,$user,'restore_redirect','/api/protected'),'reserved source path rejected');
    $proposal=$service->prepare($project,$user,'restore_redirect','/old-article');
    check176($proposal['state']==='pending' && (new RedirectRepository($work,$db))->byPath('/old-article')===null,'prepare is strictly read-only');
    denied176(fn()=>$service->approve($project,$other,$proposal['id']),'cross-operator proposal access is denied');
    // A changed evidence receipt must never be applied, and a failed approval must leave state pending.
    $pdo->prepare('UPDATE ce_migration_seo_path_evidence SET target_path = ? WHERE project_id = ?')->execute(['/missing-new-target',$project]);
    denied176(fn()=>$service->approve($project,$user,$proposal['id']),'stale mapping rejected at confirmation');
    check176($service->get($project,$user,$proposal['id'])['state']==='pending','stale proposal rollback restores pending state');
    $pdo->prepare('UPDATE ce_migration_seo_path_evidence SET target_path = ? WHERE project_id = ?')->execute([$route['path'],$project]);
    // Simulated write failure: approval state and redirect must roll back together.
    $pdo->exec("CREATE TRIGGER fail_redirect BEFORE INSERT ON ce_cms_redirects BEGIN SELECT RAISE(ABORT, 'simulated outage'); END");
    denied176(fn()=>$service->approve($project,$user,$proposal['id']),'redirect write error rolls back');
    check176($service->get($project,$user,$proposal['id'])['state']==='pending','write failure does not consume proposal');
    $pdo->exec('DROP TRIGGER fail_redirect');
    $done=$service->approve($project,$user,$proposal['id']);
    $redirect=(new RedirectRepository($work,$db))->byPath('/old-article');
    check176($done['state']==='applied' && $redirect['target_url']===$route['path'] && (int)$redirect['status_code']===301 && $redirect['enabled'],'approved fix writes verified 301 into CMS');
    denied176(fn()=>$service->approve($project,$user,$proposal['id']),'repeated approval denied');
    check176($routes->check()['ok']===true,'route index remains consistent after redirect repair');
    check176($service->candidates($project)['candidates']===[],'fixed redirect disappears from next diagnostic scan');
    $rolled=$service->rollback($project,$user,$proposal['id']);
    check176($rolled['state']==='rolled_back' && (new RedirectRepository($work,$db))->byPath('/old-article')===null,'unused approved 301 can be safely rolled back');
    denied176(fn()=>$service->rollback($project,$user,$proposal['id']),'rolled back redirect cannot be rolled back twice');
    $second=$service->prepare($project,$user,'restore_redirect','/old-article');
    $service->approve($project,$user,$second['id']);
    $pdo->exec("UPDATE ce_cms_redirects SET hit_count=1 WHERE source_path='/old-article'");
    denied176(fn()=>$service->rollback($project,$user,$second['id']),'redirect with recorded visitors cannot be silently rolled back');
    check176($service->get($project,$user,$second['id'])['state']==='applied','failed rollback preserves the remediation record');
    // Existing rules always win: never replace a human redirect, even one with wrong target.
    $pdo->prepare('INSERT INTO ce_migration_seo_path_evidence(project_id,source_path,target_path) VALUES (?,?,?)')->execute([$project,'/other-legacy',$route['path']]);
    (new RedirectRepository($work,$db))->save(['source_path'=>'/other-legacy','target_url'=>$route['path'],'status_code'=>302,'enabled'=>true]);
    denied176(fn()=>$service->prepare($project,$user,'restore_redirect','/other-legacy'),'operator redirect overrides are never overwritten');
    // A request to rebuild routing must be prepared and confirmed separately.
    $pdo->exec('DELETE FROM ce_public_routes WHERE path = '.$pdo->quote($route['path']));
    check176(!$routes->check()['ok'],'damaged index is detected before proposed repair');
    $rebuild=$service->prepare($project,$user,'rebuild_routes');
    check176($rebuild['state']==='pending','index repair requires separate approval');
    $fixed=$service->approve($project,$user,$rebuild['id']);
    check176($fixed['state']==='applied' && $routes->check()['ok'],'confirmed routing repair reconstructs real index');
    denied176(fn()=>$service->prepare($project,$user,'restore_redirect','/still-missing'),'unknown extra mapping stays non-executable');
    $pdo->prepare('INSERT INTO ce_migration_seo_path_evidence(project_id,source_path,target_path) VALUES (?,?,?)')->execute([$project,'/reject-me',$route['path']]);
    $reject=$service->prepare($project,$user,'restore_redirect','/reject-me');
    check176($service->reject($project,$user,$reject['id'])['state']==='rejected','operator rejection is durable');
    denied176(fn()=>$service->approve($project,$user,$reject['id']),'rejected action is never executed');
} finally {
    putenv('CONTENT_STORAGE='.($prior===false?'':$prior));putenv('APP_URL='.($priorUrl===false?'':$priorUrl));
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());
    }
    rmdir($work);
}
echo "AI Migration Remediation PDO acceptance PASSED\n";
