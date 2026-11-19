#!/usr/bin/env php
<?php

declare(strict_types=1);

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    if(str_starts_with($class,'CajeerEngine\\')) {
        $file=$root.'/core/'.str_replace('\\','/',substr($class,13)).'.php';
        if(is_file($file))require $file;
    }
});
use CajeerEngine\AI\AiCostControl;
use CajeerEngine\AI\AiSettings;

$check=static function(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";};
$reject=static function(callable $run,string $name)use($check):void{try{$run();}catch(InvalidArgumentException|DomainException $e){$check(true,$name);return;}throw new RuntimeException('FAIL '.$name);};
$rates=['input'=>40,'cached'=>10,'output'=>160];
$check(AiCostControl::charge(1000,0,200,$rates)===720,'one million scaling into USD micros');
$check(AiCostControl::charge(1000,500,200,$rates)===570,'cached input billed separately');
$check(AiCostControl::charge(1,0,0,$rates)===1,'fractional cost rounds upward');
$check(AiCostControl::estimatedReserve(1000,$rates)>=AiCostControl::charge(0,0,1000,$rates),'reservation uses expensive token rate');
$reject(fn()=>AiCostControl::charge(9,10,10,$rates),'invalid cached tokens rejected');
$reject(fn()=>AiCostControl::rates(['model_pricing'=>null]),'unknown model pricing fails closed');
$check(AiCostControl::rates(['model_pricing'=>$rates])===$rates,'model price accepted');
$rootFolder=sys_get_temp_dir().'/ce-cost-reg-'.bin2hex(random_bytes(8));
mkdir($rootFolder,0700);
try {
    $settings=new AiSettings($rootFolder);
    $status=$settings->status();
    $check($status['model_pricing']===$rates && $status['global_monthly_limit_cents']>0,'safe defaults and known model');
    $settings->update(['model'=>'private-custom-model']);
    $check($settings->status()['model_pricing']===null,'unknown custom model is blocked');
    $status=$settings->update(['model_pricing'=>['input'=>250,'cached'=>25,'output'=>1000],
        'global_monthly_limit_cents'=>600,'global_daily_limit_cents'=>300,'user_monthly_limit_cents'=>200,'user_daily_limit_cents'=>100]);
    $check($status['model_pricing']['output']===1000 && $status['user_daily_limit_cents']===100,'custom pricing and hard caps persisted');
    $reject(fn()=>$settings->update(['model_pricing'=>['input'=>0,'cached'=>10,'output'=>10]]),'invalid zero model price rejected');
    $reject(fn()=>$settings->update(['user_monthly_limit_cents'=>-2]),'invalid negative budget rejected');
    $check(!str_contains(file_get_contents($rootFolder.'/storage/app/ai/settings.json'),'OPENAI_API_KEY'),'cost settings contain no secrets');
} finally {
    $path=$rootFolder.'/storage/app/ai/settings.json';if(is_file($path))unlink($path);
    $path=$rootFolder.'/storage/app/ai/settings.lock';if(is_file($path))unlink($path);
    @rmdir($rootFolder.'/storage/app/ai');@rmdir($rootFolder.'/storage/app');@rmdir($rootFolder.'/storage');@rmdir($rootFolder);
}
echo "AI cost regression passed\n";
