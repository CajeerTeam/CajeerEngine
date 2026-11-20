#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Production transactional cost acceptance: run with --require-db in release CI. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')) {
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
    if(in_array('--require-db',$argv,true)){fwrite(STDERR,"FAIL PDO SQLite required\n");exit(1);}
    echo "SKIP PDO SQLite unavailable\n";exit(0);
}
use CajeerEngine\AI\AiCostControl;
use CajeerEngine\AI\AiSettings;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;

$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message."\n";};
$reject=static function(callable $fn,string $message)use($check):void{try{$fn();}catch(DomainException|RuntimeException $e){$check(true,$message);return;}throw new RuntimeException('FAIL '.$message);};
$folder=sys_get_temp_dir().'/ce-cost-'.bin2hex(random_bytes(8));mkdir($folder,0700);
try {
    $config=new ConfigRepository($root);$config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $db=new DatabaseManager($config);$pdo=$db->connection();
    $pdo->exec(file_get_contents($root.'/migrations/sqlite/0040_ai_cost_control.sql'));
    $settings=new AiSettings($folder);
    $settings->update(['global_monthly_limit_cents'=>1000,'global_daily_limit_cents'=>500,'user_monthly_limit_cents'=>200,'user_daily_limit_cents'=>100]);
    $service=new AiCostControl($db,$settings);$status=$settings->status();
    $user='00000000-0000-4000-8000-000000000001';$day=gmdate('Y-m-d');$month=gmdate('Y-m');
    $id=static fn()=>sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
    $a=$id();$b=$id();$expiry=time()+60;
    $db->transaction(fn(PDO $p)=>$service->hold($p,$a,$user,'chat',$status,100000,$expiry));
    $row=$pdo->query("SELECT reserved_micro_usd FROM ce_ai_cost_reservations LIMIT 1")->fetchColumn();
    $check($row > 0,'cost reserved before network request');
    $report=$service->report();$check((int)($report['global_balances'][$month]['reserved_micro_usd']??0)===(int)$row,'daily and monthly reservations visible');
    $db->transaction(fn(PDO $p)=>$service->settle($p,$a,['input_tokens'=>1000,'cached_input_tokens'=>200,'output_tokens'=>500]));
    $check($pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()==0,'reservation settled atomically');
    $row=$pdo->query('SELECT * FROM ce_ai_cost_events')->fetch(PDO::FETCH_ASSOC);
    $check($row['module']==='chat' && $row['input_tokens']==1000 && $row['cached_input_tokens']==200 && $row['output_tokens']==500,'actual provider usage recorded');
    $check((int)$row['cost_micro_usd']===AiCostControl::charge(1000,200,500,['input'=>40,'cached'=>10,'output'=>160]),'historic pricing snapshot correctly applied');
    $reject(fn()=>$db->transaction(fn(PDO $p)=>$service->settle($p,$a,['input_tokens'=>1,'output_tokens'=>1])),'duplicate settlement rejected');
    $db->transaction(fn(PDO $p)=>$service->hold($p,$b,$user,'support',$status,100000,$expiry));
    $db->transaction(fn(PDO $p)=>$service->release($p,$b));
    $check($pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()==0,'failed provider reservation released');
    // Atomic rollback: the reservation cannot survive an exception in its parent transaction.
    $c=$id();try{$db->transaction(function(PDO $p)use($service,$c,$user,$status,$expiry):void{
        $service->hold($p,$c,$user,'editor',$status,100000,$expiry);
        throw new RuntimeException('deliberate rollback');
    });}catch(RuntimeException){}
    $check($pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()==0,'reservation rollback and balances unchanged');
    $settings->update(['user_daily_limit_cents'=>1]);$strict=$settings->status();
    $blockedByCap=false;
    try {
        $db->transaction(fn(PDO $p)=>$service->hold($p,$id(),$user,'chat',$strict,200000,$expiry));
    } catch (DomainException $error) {
        $blockedByCap=str_contains($error->getMessage(),'AI spending limit reached');
    }
    $check($blockedByCap,'hard daily cost limit blocks before OpenAI');
    $check($pdo->query('SELECT COUNT(*) FROM ce_ai_cost_reservations')->fetchColumn()==0,'blocked request leaves no reservation');
    $settings->update(['user_daily_limit_cents'=>100]);$status=$settings->status();
    $late=$id();$db->transaction(fn(PDO $p)=>$service->hold($p,$late,$user,'rag',$status,100000,time()-1));
    $db->transaction(fn(PDO $p)=>$service->reap($p,time()));
    $check($pdo->query("SELECT COUNT(*) FROM ce_ai_cost_events WHERE state='unverified'")->fetchColumn()==1,'expired potentially charged request is accounted conservatively');
    $report=$service->report();
    $check(count($report['by_module'])>=2 && count($report['by_model'])===1 && count($report['by_user'])===1,'cost report groups by module, model, user');
} finally {
    foreach (glob($folder.'/storage/app/ai/*')?:[] as $path) @unlink($path);
    @rmdir($folder.'/storage/app/ai');@rmdir($folder.'/storage/app');@rmdir($folder.'/storage');@rmdir($folder);
}
echo "AI cost real PDO acceptance passed\n";
