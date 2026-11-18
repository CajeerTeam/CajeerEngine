#!/usr/bin/env php
<?php
declare(strict_types=1);
use CajeerEngine\Migration\{MigrationCutover,MigrationPlanner,PlanningSource};
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root){$prefix='CajeerEngine\\';if(str_starts_with($class,$prefix)){$file=$root.'/core/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require $file;}});
function check(bool $v,string $label):void {if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$type=['handle'=>'articles','fields'=>[['handle'=>'description','type'=>'text','label'=>'Description','required'=>false]]];
$r=PlanningSource::record('post',['id'=>'1','title'=>'First','slug'=>'first','body'=>'Test','date'=>'2020-01-01 00:00:00','status'=>'publish']);
$mapping=['types'=>['post'=>['target'=>'articles','fields'=>['data:description'=>['from'=>'body','transform'=>'text']]]],'statuses'=>['publish'=>'published'],'users'=>[],'user_roles'=>[],'locale'=>'ru','conflict'=>'fail'];
$payload=MigrationPlanner::payload($r,$mapping['types']['post'],$type,'ru','published');
$hash=MigrationPlanner::fingerprint($payload);
$base=['source_type'=>'post','source_id'=>'1','target_type'=>'articles','target_id'=>'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','action'=>'create','revision'=>1,'slug'=>'first'];
$plan=['records'=>[['source_type'=>'post','source_id'=>'1','payload_sha256'=>$hash,'slug'=>'first']]];
$project=['mapping'=>$mapping,'plan'=>$plan];
$receipt=['records'=>[$base],'users'=>[]];
$entry=['id'=>$base['target_id'],'revision'=>1,'slug'=>'first','status'=>'published'];
$lookup=function(string $kind,mixed $v)use($type,$entry){return match($kind){'type'=>$type,'entry'=>$entry,'slug'=>null,default=>null};};
$sync=static fn(string $key)=>null;
$res=MigrationCutover::reconcile(['records'=>[$r]],$project,$receipt,$lookup,$sync);
check($res['counts']['existing']===1&&$res['changes']===[]&&$res['problems']===[],'unchanged imported content is not re-imported');
$new=$r;$new['id']='2';$new['fields']['slug']='second';$new['fields']['title']='Second';
$res=MigrationCutover::reconcile(['records'=>[$r,$new]],$project,$receipt,$lookup,$sync);
check(count($res['changes'])===1&&$res['changes'][0]['action']==='create','new source record schedules real creation');
$changed=$r;$changed['fields']['body']='New text';
$res=MigrationCutover::reconcile(['records'=>[$changed]],$project,$receipt,$lookup,$sync);
check(count($res['changes'])===1&&$res['changes'][0]['action']==='update','changed source record schedules real update');
$edited=$entry;$edited['revision']=2;
$lookupEdited=function(string $kind,mixed $v)use($type,$edited){return match($kind){'type'=>$type,'entry'=>$edited,'slug'=>null,default=>null};};
$res=MigrationCutover::reconcile(['records'=>[$changed]],$project,$receipt,$lookupEdited,$sync);
check($res['changes']===[]&&$res['problems'][0]['code']==='editor_revision_or_slug_conflict','editor revision drift blocks overwrite');
$res=MigrationCutover::reconcile(['records'=>[]],$project,$receipt,$lookup,$sync);
check($res['counts']['missing_source']===1&&$res['problems'][0]['code']==='missing_at_source','missing source does not silently delete target');
$lookupCollide=function(string $kind,mixed $v)use($type,$entry){return match($kind){'type'=>$type,'entry'=>$entry,'slug'=>$entry,default=>null};};
$res=MigrationCutover::reconcile(['records'=>[$r,$new]],$project,$receipt,$lookupCollide,$sync);
check($res['problems'][0]['code']==='target_slug_collision','new slug conflict blocks creation');
$plan['records'][0]['action']='skip';$receipt['records'][0]['action']='skip';$project['plan']=$plan;
$res=MigrationCutover::reconcile(['records'=>[$r]],$project,$receipt,$lookup,$sync);
check($res['counts']['skipped']===1&&$res['problems']===[],'intentionally skipped source remains skipped');
