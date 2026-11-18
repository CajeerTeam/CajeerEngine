<?php

declare(strict_types=1);

/** Real SQLite acceptance: AnalyticsHub report sync -> AI insights -> budgeted OpenAI -> failure rollback. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void {
    if(str_starts_with($name,'CajeerEngine\\')) {
        $path=$root.'/core/'.str_replace('\\','/',substr($name,13)).'.php';
        if(is_file($path))require $path;
    }
});
use CajeerEngine\AI\{AiExternalAnalytics,AiProviderException,AiSettings,OpenAiClient};
use CajeerEngine\Analytics\Reporting\{AnalyticsHub,ReportProvider,ReportComparison};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Queue\FileQueueDriver;

function ensure(bool $condition,string $text):void{if(!$condition)throw new RuntimeException('FAIL '.$text);echo 'PASS '.$text."\n";}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite required');
    echo "SKIP AI Analytics PDO integration (pdo_sqlite unavailable)\n";exit(0);
}
$work=sys_get_temp_dir().'/ce-ai-analytics-'.bin2hex(random_bytes(8));mkdir($work,0700);
foreach(['storage','storage/app','storage/app/ai','storage/queue','config'] as $part)mkdir($work.'/'.$part,0700,true);
$old=getenv('OPENAI_API_KEY');$oldVault=getenv('INTEGRATIONS_KEY');
putenv('INTEGRATIONS_KEY=base64:'.base64_encode(random_bytes(32)));
try {
    $config=new ConfigRepository($work);
    $config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $config->set('analytics.enabled',true);$config->set('integrations.enabled',true);
    $db=new DatabaseManager($config);
    foreach(['0031_ai_chat','0032_ai_content_editor'] as $migration) $db->connection()->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
    foreach(['0015_analytics_hub','0017_analytics_sync_runs'] as $migration)$db->connection()->exec((string)file_get_contents($root.'/migrations/sqlite/'.$migration.'.sql'));
    $provider=new class implements ReportProvider {
        public function id():string{return 'metrika';}
        public function validateCredential(array $credential):void {if(($credential['type']??null)!=='oauth_token')throw new InvalidArgumentException('Bad test credential');}
        public function report(string $resource,array $credential,string $from,string $to,?string $goal):array {
            $current=$to>gmdate('Y-m-d',time()-20*86400);$count=$current?60:100;
            $rows=[];$date=new DateTimeImmutable($from,new DateTimeZone('UTC'));
            for($i=0;$i<=((int)$date->diff(new DateTimeImmutable($to))->days);$i++)$rows[]=['label'=>$date->modify('+'.$i.' day')->format('Y-m-d'),'values'=>[(float)$count]];
            return ['provider'=>'metrika','resource'=>$resource,'from'=>$from,'to'=>$to,'timezone'=>'UTC','fetched_at'=>gmdate(DATE_ATOM),
                'metrics'=>['ym:s:visits'],'labels'=>['Visits'],
                'sections'=>['overview'=>['rows'=>[['label'=>'Total','values'=>[(float)$count*count($rows)]]], 'quality'=>[],'truncated'=>false],
                    'daily'=>['rows'=>$rows,'quality'=>[]], 'pages'=>['rows'=>[['label'=>'/customer?token=private-customer-token','values'=>[55.]]],'quality'=>[]]]];
        }
    };
    $hub=new AnalyticsHub($db,new SecretVault('base64:'.base64_encode(random_bytes(32))),new FileQueueDriver($work),$config,$provider);
    $source=$hub->save(null,['provider'=>'metrika','name'=>'Test source','resource'=>'1234','goal'=>null,'enabled'=>true,'credential'=>['type'=>'oauth_token','token'=>'private-token']]);
    $id=$source['id'];$to=gmdate('Y-m-d',time()-86400);$from=gmdate('Y-m-d',time()-20*86400);
    [$previousFrom,$previousTo]=ReportComparison::previous($from,$to);
    foreach([[$previousFrom,$previousTo],[$from,$to]] as [$a,$b])$hub->sync(['id'=>$id,'revision'=>1,'from'=>$a,'to'=>$b]);
    $settings=new AiSettings($work);$settings->update(['api_key'=>'sk-test-123456789012345678901234567890','monthly_token_limit'=>40000,'max_output_tokens'=>256]);
    $calls=0;$failure=false;$received='';
    $client=new class($calls,$failure,$received) extends OpenAiClient {
        public function __construct(private int &$calls,private bool &$fail,private string &$seen){}
        public function complete(string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null):array {
            $this->calls++;$this->seen=json_encode($input,JSON_THROW_ON_ERROR);
            if(!str_starts_with($key,'sk-test-'))throw new RuntimeException('Missing protected credential');
            if($this->fail)throw new AiProviderException('Simulated outage',503);
            return ['text'=>'Трафик снизился относительно предыдущего периода. Проверьте источники.',
                'usage'=>['input_tokens'=>105,'output_tokens'=>45],'response_id'=>'response-local'];
        }
    };
    $service=new AiExternalAnalytics($hub,$db,$settings,$client);
    $insights=$service->insights($id,$from,$to);
    ensure($insights['available']&&$insights['comparison_status']==='ready'&&$insights['changes'][0]['percent']===-40.0,'Real SQLite snapshots compared for same provider');
    ensure(!str_contains(json_encode($insights),'private-token')&&!str_contains(json_encode($insights),'private-customer-token'),'No credentials or URL queries in insights');
    $uid='11111111-1111-4111-8111-111111111111';
    $reply=$service->explain($uid,$id,$from,$to);
    ensure($calls===1&&str_contains($reply['explanation'],'Трафик'),'Real budgeted OpenAI client called');
    ensure(!str_contains($received,'1234')&&!str_contains($received,'private-customer-token')&&!str_contains($received,'private-token'),'Provider context excludes resource and URL identifiers');
    $usage=$db->connection()->query('SELECT used_tokens,reserved_tokens FROM ce_ai_usage')->fetch(PDO::FETCH_ASSOC);
    ensure((int)$usage['used_tokens']===150&&(int)$usage['reserved_tokens']===0,'Exact provider usage settled');
    $failure=true;
    try{$service->explain($uid,$id,$from,$to);throw new RuntimeException('Expected provider outage');}catch(AiProviderException $e){ensure($e->httpStatus===503,'Provider failure propagates');}
    ensure((int)$db->connection()->query('SELECT reserved_tokens FROM ce_ai_usage')->fetchColumn()===0,'Failure releases budget');
    ensure((int)$db->connection()->query('SELECT COUNT(*) FROM ce_ai_editor_jobs')->fetchColumn()===0,'No orphan lease');
    echo "AI External Analytics real PDO integration PASSED\n";
}finally{
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($work);
    putenv('OPENAI_API_KEY='.($old===false?'':$old));
    putenv('INTEGRATIONS_KEY='.($oldVault===false?'':$oldVault));
}
