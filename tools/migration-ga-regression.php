#!/usr/bin/env php
<?php

declare(strict_types=1);

use CajeerEngine\Migration\{MigrationIntegrity,MigrationCutover,MigrationPlanner,PlanningSource};

$root = dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    $prefix = 'CajeerEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
});

$passed = 0;
function ensureGa(bool $value, string $label): void {
    global $passed;
    if (!$value) throw new RuntimeException('FAILED ' . $label);
    $passed++;
    echo 'PASS ' . $label . PHP_EOL;
}
function digestGa(array $data): string {
    return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function snapshotGa(array $entry, string $reason): array {
    return array_intersect_key($entry, array_flip(['revision','title','slug','status','locale','data'])) + ['reason'=>$reason];
}
$base=['id'=>'a1111111-1111-1111-1111-111111111111','title'=>'A post','slug'=>'a-post','status'=>'published','locale'=>'ru','data'=>['body'=>'original'],'revision'=>1];
$history=[snapshotGa($base,'created')];
ensureGa(MigrationIntegrity::revisionProblem($base,$history,1)===null,'valid source import accepted');
$changed=$base;$changed['title']='Edited by operator';
ensureGa(MigrationIntegrity::revisionProblem($changed,$history,1)==='target_snapshot_mismatch','out-of-band content mutation rejected');
$changed=$base;$changed['revision']=2;$changed['data']=['body'=>'edited'];
$regular=[snapshotGa($changed,'updated'),...$history];
ensureGa(MigrationIntegrity::revisionProblem($changed,$regular,1)==='unverified_editorial_revision','editor revision blocks cutover');
$media=$changed;$media['data']=['body'=>'/media/new.jpg'];
$mediaHistory=[snapshotGa($media,'migration_media'),...$history];
ensureGa(MigrationIntegrity::revisionProblem($media,$mediaHistory,1)===null,'labelled media rewrite is trusted');
$seo=$media;$seo['revision']=3;$seo['data']=['body'=>'/new-path'];
$seoHistory=[snapshotGa($seo,'migration_seo'),...$mediaHistory];
ensureGa(MigrationIntegrity::revisionProblem($seo,$seoHistory,1)===null,'labelled SEO rewrite is trusted');
ensureGa(MigrationIntegrity::revisionProblem($seo,$seoHistory,2)===null,'later committed checkpoint starts new trusted chain');
ensureGa(MigrationIntegrity::revisionProblem($seo,[$seoHistory[0],$history[0]],1)==='target_revision_history_missing','missing middle revision refuses approval');
$legacyMedia=['status'=>'done','original_revision'=>1,'updated_sha'=>digestGa($media['data'])];
ensureGa(MigrationIntegrity::revisionProblem($media,[snapshotGa($media,'updated'),...$history],1,$legacyMedia)===null,'verified pre-1.5.0 media rewrite preserved');
$invalidMedia=$legacyMedia;$invalidMedia['updated_sha']=str_repeat('b',64);
ensureGa(MigrationIntegrity::revisionProblem($media,[snapshotGa($media,'updated'),...$history],1,$invalidMedia)==='unverified_editorial_revision','forged legacy media digest rejected');
ensureGa(MigrationIntegrity::revisionProblem($seo,[snapshotGa($seo,'updated'),...$mediaHistory],1,$legacyMedia)==='unverified_editorial_revision','unlabelled legacy SEO/editor revision cannot be silently approved');
$modified=$seo;$modified['status']='draft';
ensureGa(MigrationIntegrity::revisionProblem($modified,$seoHistory,1)==='target_snapshot_mismatch','current status drift rejected');
ensureGa(MigrationIntegrity::revisionProblem($base,$history,2)==='invalid_target_revision','regressed revision refused');
$approved=[['source_type'=>'post','source_id'=>'55','action'=>'create','target_type'=>'articles']];
$receipt=[['source_type'=>'post','source_id'=>'55','action'=>'create','target_type'=>'articles','target_id'=>$base['id']]];
ensureGa(MigrationIntegrity::receiptProblems($approved,$receipt)===[],'matching import receipt passes');
ensureGa(MigrationIntegrity::receiptProblems($approved,[])=== [['source'=>'post:55','code'=>'missing_import_receipt']],'missing receipt blocked');
ensureGa(MigrationIntegrity::receiptProblems($approved,[$receipt[0],$receipt[0]])[0]['code']==='duplicate_import_receipt','duplicate receipt blocked');
$alien=$receipt[0];$alien['source_id']='66';
ensureGa(MigrationIntegrity::receiptProblems($approved,[$receipt[0],$alien])[0]['code']==='unapproved_import_receipt','unapproved source blocked');
$updatePlan=[['source_type'=>'post','source_id'=>'55','action'=>'update','target_type'=>'articles','target_id'=>$base['id'],'slug'=>'a-post']];
$forged=$receipt[0];$forged['action']='update';$forged['target_id']='b2222222-2222-2222-2222-222222222222';$forged['slug']='a-post';
ensureGa(MigrationIntegrity::receiptProblems($updatePlan,[$forged])[0]['code']==='import_receipt_disagrees_with_plan','updated entry cannot be redirected to another UUID');
$forged=$receipt[0];$forged['target_id']='../../etc/passwd';
ensureGa(MigrationIntegrity::receiptProblems($approved,[$forged])[0]['code']==='import_receipt_disagrees_with_plan','invalid destination UUID rejected');
$altered=$receipt[0];$altered['target_type']='pages';
ensureGa(MigrationIntegrity::receiptProblems($approved,[$altered])[0]['code']==='import_receipt_disagrees_with_plan','tampered target type rejected');
$second=$receipt[0];$second['source_id']='56';$two=[...$approved,['source_type'=>'post','source_id'=>'56','action'=>'create','target_type'=>'articles']];
ensureGa(MigrationIntegrity::receiptProblems($two,[$receipt[0],$second])[0]['code']==='duplicate_import_target','multiple records targeting same UUID rejected');
// Reconciliation must use the verified latest revision, never the obsolete pre-SEO receipt.
$type=['handle'=>'articles','fields'=>[]];
$source=PlanningSource::record('post',['id'=>'55','title'=>'A post','slug'=>'a-post','body'=>'new','status'=>'publish','date'=>'2024-01-01 00:00:00']);
$mapping=['types'=>['post'=>['target'=>'articles','fields'=>[]]],'statuses'=>['publish'=>'published'],'users'=>[],'user_roles'=>[],'locale'=>'ru','conflict'=>'fail'];
$payload=MigrationPlanner::payload($source,$mapping['types']['post'],$type,'ru','published');
$plan=['records'=>[['source_type'=>'post','source_id'=>'55','action'=>'create','target_type'=>'articles','payload_sha256'=>str_repeat('a',64)]]];
$project=['mapping'=>$mapping,'plan'=>$plan];
$receiptForSync=['records'=>[$receipt[0]+['revision'=>1,'slug'=>'a-post']], 'users'=>[]];
$lookup=static fn(string $kind,mixed $id):?array=>match($kind){'type'=>$type,'entry'=>$seo,'slug'=>null,default=>null};
$report=MigrationCutover::reconcile(['records'=>[$source]],$project,$receiptForSync,$lookup,static fn()=>null,static fn()=>3);
ensureGa(($report['changes'][0]['expected_revision']??null)===3,'trusted post-SEO revision is usable for incremental sync');
$report=MigrationCutover::reconcile(['records'=>[$source]],$project,$receiptForSync,$lookup,static fn()=>null,static fn()=>null);
ensureGa(($report['problems'][0]['code']??null)==='editor_revision_or_slug_conflict','unverified revision cannot be synchronized');
ensureGa($passed===22,'all GA integrity and reconciliation scenarios covered');
