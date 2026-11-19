<?php

declare(strict_types=1);

/** Acceptance on real CMS file storage + PDO AnalyticsHub snapshots + revision-protected AI Editor. */
$root=dirname(__DIR__);
spl_autoload_register(static function(string $name)use($root):void {
    if(str_starts_with($name,'CajeerEngine\\')) {
        $path=$root.'/core/'.str_replace('\\','/',substr($name,13)).'.php';
        if(is_file($path))require $path;
    }
});
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)) {
    if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite required for SEO insights integration');
    echo "SKIP: pdo_sqlite not available\n";exit(0);
}

use CajeerEngine\AI\{AiSeoContentInsights,AiSeoAssistant,AiSettings,AiContentEditor,OpenAiClient};
use CajeerEngine\Analytics\Reporting\{AnalyticsHub,ReportProvider,ReportComparison};
use CajeerEngine\Content\{ContentTypeRepository,ContentEntryRepository};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Public\RouteIndex;
use CajeerEngine\Queue\FileQueueDriver;
use CajeerEngine\Runtime\ConfigRepository;

$check=static function(bool $ok,string $message):void { if(!$ok)throw new RuntimeException('FAIL '.$message); echo 'PASS '.$message."\n";};
$dir=sys_get_temp_dir().'/ce-seo-insights-'.bin2hex(random_bytes(7));mkdir($dir,0700);
foreach(['storage','storage/app','storage/queue','config'] as $folder)mkdir($dir.'/'.$folder,0700,true);
$prior=[];foreach(['CONTENT_STORAGE','CMS_STORAGE','APP_URL','INTEGRATIONS_KEY','OPENAI_API_KEY'] as $name)$prior[$name]=getenv($name);
try {
    putenv('CONTENT_STORAGE=file');putenv('CMS_STORAGE=file');putenv('APP_URL=https://site.example');
    putenv('INTEGRATIONS_KEY=base64:'.base64_encode(random_bytes(32)));
    $config=new ConfigRepository($root);
    $config->set('database.default','sqlite');$config->set('database.connections.sqlite.database',':memory:');
    $config->set('app.url','https://site.example');$config->set('analytics.enabled',true);$config->set('integrations.enabled',true);
    $db=new DatabaseManager($config);
    foreach(['0015_analytics_hub','0017_analytics_sync_runs','0031_ai_chat','0032_ai_content_editor','0040_ai_cost_control'] as $file)
        $db->connection()->exec((string)file_get_contents($root.'/migrations/sqlite/'.$file.'.sql'));
    $types=new ContentTypeRepository($dir,$db);$entries=new ContentEntryRepository($dir,$db);
    $types->create(['handle'=>'pages','name'=>'Pages','fields'=>[['handle'=>'body','type'=>'richtext']]]);
    $article=$entries->create('pages',['title'=>'Setup guide','slug'=>'setup','status'=>'published',
        'locale'=>'en','data'=>['body'=>'<p>Actual installation instructions with documented examples.</p>']]);
    $routes=new RouteIndex($dir,$db);$routes->rebuild();
    $route=$routes->forEntry($article['id']);$check(is_array($route)&&$route['kind']==='page','real CMS route generated');
    $path=$route['path'];
    $today=gmdate('Y-m-d',time()-86400);$from=gmdate('Y-m-d',time()-14*86400);
    [$pf,$pt]=ReportComparison::previous($from,$today);
    $provider=new class($from,$path) implements ReportProvider {
        public function __construct(private string $currentFrom,private string $path) {}
        public function id(): string {return 'metrika';}
        public function validateCredential(array $credentials):void {
            if(($credentials['type']??null)!=='oauth_token')throw new InvalidArgumentException('Invalid fixture credential');
        }
        public function report(string $resource,array $credentials,string $from,string $to,?string $goal):array {
            $current=$from===$this->currentFrom;
            return ['provider'=>'metrika','resource'=>$resource,'from'=>$from,'to'=>$to,'timezone'=>'UTC',
                'fetched_at'=>gmdate(DATE_ATOM),'metrics'=>['ym:s:visits'],'labels'=>['Visits'],
                'sections'=>['overview'=>['rows'=>[['label'=>'Total','values'=>[$current?1000.:1400.]]],'quality'=>[], 'truncated'=>false],
                    'pages'=>['rows'=>[
                        ['label'=>'https://site.example'.$this->path.'?utm=search','values'=>[$current?50.:100.]],
                        ['label'=>'https://unrelated.example'.$this->path,'values'=>[900.]],
                    ],'quality'=>[],'truncated'=>false]]];
        }
    };
    $hub=new AnalyticsHub($db,new SecretVault('base64:'.base64_encode(random_bytes(32))),new FileQueueDriver($dir),$config,$provider);
    $conn=$hub->save(null,['provider'=>'metrika','name'=>'Search property','resource'=>'1234','enabled'=>true,'goal'=>null,
        'credential'=>['type'=>'oauth_token','token'=>'integration-fixture-key']]);
    foreach([[$pf,$pt],[$from,$today]] as [$a,$b])$hub->sync(['id'=>$conn['id'],'revision'=>1,'from'=>$a,'to'=>$b]);
    $service=new AiSeoContentInsights($hub,new AiSeoAssistant($entries,$types,$routes),$routes,$config);
    $result=$service->report($conn['id'],$from,$today);
    $check($result['available']&&$result['comparison_status']==='ready','real synchronized report and previous period used');
    $check(count($result['items'])===1&&$result['items'][0]['entry_id']===$article['id'],'foreign URL and query excluded; real CMS route joined');
    $item=$result['items'][0];
    $check($item['change_percent']===-50.0&&$item['trend']==='decline','exact same-provider per-page trend detected');
    $check(in_array('missing_seo_title',array_column($item['issues'],'code'),true),'SEO issues derived from actual saved entry');
    $check($item['priority']>40&&count($item['recommendations'])>0,'prioritized remediation returned');
    $settings=new AiSettings($dir);$settings->update(['api_key'=>'sk-test-123456789012345678901234567890',
        'monthly_token_limit'=>50000,'max_output_tokens'=>256]);
    $client=new class extends OpenAiClient {
        public function complete(string $key,string $model,array $input,int $maxOutput,?callable $onDelta=null):array {
            if(!str_starts_with($key,'sk-test-'))throw new RuntimeException('Protected AI key not used');
            return ['text'=>'Setup guide: installation documentation',
                'usage'=>['input_tokens'=>44,'output_tokens'=>14],'response_id'=>'response-fixture'];
        }
    };
    $editor=new AiContentEditor($db,$settings,$client,$entries,$types);
    $user='11111111-1111-4111-8111-111111111111';
    $proposal=$editor->propose($user,['type'=>'pages','entry_id'=>$article['id'],'field'=>'seo_title',
        'mode'=>'seo_title','instructions'=>'Rewrite only SEO metadata']);
    $check(($entries->findById('pages',$article['id'])['data']['seo_title']??null)===null,'proposal does not publish automatically');
    $editor->apply($user,$proposal['id'],true);
    $after=$service->report($conn['id'],$from,$today);
    $check(!in_array('missing_seo_title',array_column($after['items'][0]['issues'],'code'),true),'approved change updates real CMS and subsequent SEO insights');
    echo "SEO & Content Insights real PDO integration passed\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($dir);
    foreach($prior as $name=>$value)putenv($name.'='.($value===false?'':$value));
}
