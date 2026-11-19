<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/core/AI/AiBudgetLease.php';
require_once dirname(__DIR__).'/core/AI/AiMigrationCopilot.php';
require_once dirname(__DIR__).'/core/Http/RouteRegistry.php';

use CajeerEngine\AI\AiMigrationCopilot;
use CajeerEngine\Http\RouteRegistry;

function ok(bool $test,string $label):void{if(!$test)throw new RuntimeException($label);}
$routes=array_column(RouteRegistry::all(),5,2);
ok(($routes['api.v1.ai.migration.report']??[])===['migration:read'],'Migration report must require RBAC');
ok(($routes['api.v1.ai.migration.explain']??[])===['migration:read'],'AI explanation must require migration RBAC');
$id=str_repeat('b',32);
$project=['id'=>$id,'name'=>'WordPress demo','source'=>'wordpress-wxr','status'=>'completed'];
$validation=['ready'=>false,'checked_at'=>'2026-11-18T12:00:00+00:00', 'counts'=>['source'=>50,'changed'=>2,'media_invalid'=>1],
    'problems'=>[['source'=>'post:7','code'=>'target_missing'],['source'=>'post:7','code'=>'target_missing'],
        ['source'=>'https://old.example.com/?token=CANARY_8AF98','code'=>'legacy_query_redirect_broken'],
        ['source'=>'media/a.jpg','code'=>'media_missing_or_corrupt']],
    'drift'=>[['source'=>'post:8','code'=>'target_slug_differs']]];
$report=AiMigrationCopilot::summarize($project,$validation,[['source'=>'media/a.jpg','code'=>'media_missing_or_corrupt']],[]);
ok($report['finding_total']===4,'Deduplicated findings include saved and live rows');
ok($report['findings'][0]['severity']==='critical','Critical findings sorted first');
ok($report['finding_counts']['target_missing']===1,'Duplicate evidence counted once');
ok($report['finding_counts']['media_missing_or_corrupt']===1,'Repeated live check deduplicated');
ok(str_contains($report['next_step'],$id),'Safe CLI uses validated project ID');
ok($report['cutover_confirmed']===false && !$report['validation_ready'],'No false cutover/readiness');
ok(!str_contains(json_encode($report,JSON_THROW_ON_ERROR),'CANARY_8AF98'),'Sensitive URL query suppressed');
$empty=AiMigrationCopilot::summarize(['id'=>$id,'name'=>'DLE','status'=>'draft','source'=>'dle-db'],null);
ok(!$empty['import_complete']&&!$empty['validation_available']&&$empty['finding_total']===0,'Draft is not marked migrated');
$ready=AiMigrationCopilot::summarize($project,['ready'=>true,'counts'=>[],'checked_at'=>gmdate(DATE_ATOM),'cutover'=>['activated_at'=>gmdate(DATE_ATOM)]],[],[]);
ok($ready['validation_ready']&&$ready['cutover_confirmed'],'Cutover is reported only with receipt');
$private=$report;
$private['project_id']='CANARY-PROJECT-SECRET';
$private['name']='CANARY-NAME-SECRET';
$private['findings'][0]['source']='CANARY-PATH-SECRET';
$private['warnings']=[];
$private['checks']['resource_url']='CANARY-URL-SECRET';
$evidence=json_encode(AiMigrationCopilot::modelEvidence($private),JSON_THROW_ON_ERROR);
ok(!str_contains($evidence,'CANARY-'),'Model receives no project IDs, names or source paths');
ok(str_contains($evidence,'target_missing'),'Model receives grounded issue codes');
// Fresh checks that differ from a saved validation must remain visible.
$changed=AiMigrationCopilot::summarize($project,['ready'=>true,'counts'=>[]], [['source'=>'/old-path','code'=>'legacy_path_redirect_missing_or_changed']]);
ok($changed['finding_total']===1 && $changed['validation_ready'],'Saved readiness is not confused with current evidence');
$overflow=array_fill(0,110,['source'=>'/x','code'=>'missing_public_route']);
foreach($overflow as $i=>&$item)$item['source']='/x'.$i;
unset($item);
$bounded=AiMigrationCopilot::summarize($project,['ready'=>false,'problems'=>$overflow]);
ok($bounded['finding_total']===110 && count($bounded['findings'])===100,'Large report is bounded but counted');
ok(count($bounded['warnings'])>0,'Truncation warning is visible');
echo "AI Migration Copilot: RBAC, safe evidence, prioritization, deduplication and truncation passed\n";
