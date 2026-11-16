<?php
declare(strict_types=1);
use CajeerEngine\Analytics\Reporting\{AnalyticsHub,ReportComparison,ReportProvider};
use CajeerEngine\Database\{DatabaseManager,MigrationRunner};
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Queue\FileQueueDriver;
use CajeerEngine\Backup\{DatabaseBackup,RecoveryBundle};
$root=dirname(__DIR__);spl_autoload_register(static function($c)use($root){if(str_starts_with($c,'CajeerEngine\\')){$p=$root.'/core/'.str_replace('\\','/',substr($c,13)).'.php';if(is_file($p))require $p;}});
function check(bool $ok,string $what):void{if(!$ok)throw new RuntimeException($what);echo "PASS $what\n";}
function sample(string $from,string $to,float $n):array{return ['provider'=>'metrika','resource'=>'1234','from'=>$from,'to'=>$to,'metrics'=>['users','ctr'],'labels'=>['Users','CTR'],'timezone'=>'UTC','sections'=>['overview'=>['rows'=>[['label'=>'Total','values'=>[$n,.1]]],'truncated'=>false,'quality'=>[]]],'fetched_at'=>gmdate(DATE_ATOM)];}
$from=gmdate('Y-m-d',time()-8*86400);$to=gmdate('Y-m-d',time()-2*86400);[$pf,$pt]=ReportComparison::previous($from,$to);$a=sample($from,$to,20);$b=sample($pf,$pt,10);
check((strtotime($pt)-strtotime($pf))===6*86400&&strtotime($from)-strtotime($pt)===86400,'equal non-overlapping periods');
$c=ReportComparison::compare($a,$b,$pf,$pt);check($c['status']==='ready'&&$c['metrics'][0]['delta']===10.0&&$c['metrics'][0]['percent']===100.0,'real totals compared');
check(ReportComparison::compare($a,null,$pf,$pt)['status']==='missing','missing period not zero');
$b0=sample($pf,$pt,0);check(ReportComparison::compare($a,$b0,$pf,$pt)['metrics'][0]['percent']===null,'zero denominator not infinity');
$b0['sections']['overview']['rows'][0]['values'][0]=null;check(ReportComparison::compare($a,$b0,$pf,$pt)['metrics'][0]['delta']===null,'missing metric not zero');
$bad=$b;$bad['provider']='ga4';check(ReportComparison::compare($a,$bad,$pf,$pt)['status']==='incompatible','provider isolation');
$bad=$b;$bad['timezone']='Europe/Warsaw';check(ReportComparison::compare($a,$bad,$pf,$pt)['status']==='incompatible','timezone mismatch rejected');
$bad=$b;$bad['sections']['overview']['quality']['available_days']=2;check(ReportComparison::compare($a,$bad,$pf,$pt)['status']==='incomplete','partial days not compared');
$bad=$b;$bad['sections']['overview']['quality']['thresholded']=true;check(ReportComparison::compare($a,$bad,$pf,$pt)['status']==='incomplete','thresholded report not compared');
$bad=$b;$bad['sections']['overview']['quality']['sampled']=true;check(ReportComparison::compare($a,$bad,$pf,$pt)['sampled'],'sampling disclosed');
$today=gmdate('Y-m-d');$x=sample($today,$today,1);[$p1,$p2]=ReportComparison::previous($today,$today);check(ReportComparison::compare($x,sample($p1,$p2,1),$p1,$p2)['status']==='incomplete','today partial day not compared');
check(ReportComparison::previous('2000-01-01','2000-01-02')===null,'history boundary');
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){if(in_array('--require-db',$argv,true))throw new RuntimeException('pdo_sqlite required');echo "SKIP SQL GA regression (pdo_sqlite unavailable)\n";exit(0);}
$work=sys_get_temp_dir().'/ce-ga-'.bin2hex(random_bytes(8));mkdir($work,0700);
try{
 $config=new ConfigRepository($work);$config->set('database',['default'=>'sqlite','connections'=>['sqlite'=>['database'=>$work.'/test.sqlite']]]);$db=new DatabaseManager($config);$pdo=$db->connection();
 // Upgrade an actual 1.3.9 database, not just a clean install.
 $m=$work.'/old-migrations/sqlite';mkdir($m,0700,true);foreach(glob($root.'/migrations/sqlite/*.sql') as $f)if(!str_contains($f,'0017_'))copy($f,$m.'/'.basename($f));
 (new MigrationRunner($pdo,$work.'/old-migrations'))->run('sqlite');
 $vault=new SecretVault('base64:'.base64_encode(random_bytes(32)));$queue=new FileQueueDriver($work);
 $id=bin2hex(random_bytes(16));$sealed=$vault->seal('analytics:report:v1:'.$id.':metrika:1234',json_encode(['token'=>'test-token']));
 $pdo->prepare("INSERT INTO analytics_connections (id,provider,name,resource_id,enabled,revision,credential,lease_until) VALUES (?,'metrika','Old source','1234',1,1,?,0)")->execute([$id,$sealed]);
 $pdo->prepare('INSERT INTO analytics_reports (connection_id,date_from,date_to,fetched_at,report_json) VALUES (?,?,?,?,?)')->execute([$id,$from,$to,$a['fetched_at'],json_encode($a)]);
 (new MigrationRunner($pdo,$root.'/migrations'))->run('sqlite');
 $provider=new class implements ReportProvider {public int $calls=0;public bool $fail=false;public function id():string{return 'metrika';}public function validateCredential(array $c):void{}public function report(string $resource,array $credential,string $from,string $to,?string $goal):array{$this->calls++;if($this->fail)throw new RuntimeException('Analytics provider HTTP 503');return sample($from,$to,10+$this->calls);}};
 $hub=new AnalyticsHub($db,$vault,$queue,$config,$provider);
 check($hub->report($id,$from,$to)['report']['sections']['overview']['rows'][0]['values'][0]===20,'upgrade preserves existing snapshot');
 $job=fn($k,$f,$t)=>['id'=>$id,'revision'=>1,'from'=>$f,'to'=>$t,'_idempotency_key'=>$k];
 $j1=$job('ga:first',$pf,$pt);$j2=$job('ga:second',$from,$to);$hub->sync($j1);$hub->sync($j2);$hub->sync($j1);check($provider->calls===2,'old job replay after newer job suppressed');
 $r=$hub->report($id,$from,$to);check($r['comparison']['status']==='ready'&&count($r['sync_history'])===2,'SQL comparison and durable receipts');
 $provider->fail=true;$j3=$job('ga:retry',$from,$to);try{$hub->sync($j3);throw new LogicException('Expected failure');}catch(RuntimeException $e){check($e->getMessage()==='Analytics provider HTTP 503','failure sanitized');}
 $r=$hub->report($id,$from,$to);check($r['report']['sections']['overview']['rows'][0]['values'][0]===12&&count(array_filter($r['sync_history'],fn($v)=>$v['status']==='failed'))===1,'failed refresh preserves report and records failure');
 $provider->fail=false;$hub->sync($j3);$r=$hub->report($id,$from,$to);check(count(array_filter($r['sync_history'],fn($v)=>$v['attempts']===2&&$v['status']==='success'))===1,'retry updates durable receipt');
 $pdo->prepare("UPDATE analytics_connections SET lease_until=0,lease_token='expired' WHERE id=?")->execute([$id]);$pdo->prepare("UPDATE analytics_sync_runs SET status='running' WHERE connection_id=? AND job_key=?")->execute([$id,hash('sha256','ga:first')]);
 $hub->sync($job('ga:after-crash',$from,$to));check(count(array_filter($hub->report($id)['sync_history'],fn($v)=>$v['status']==='interrupted'))===1,'expired lease recorded as interrupted');
 $dbBackup=new DatabaseBackup($work,$config);$file=$work.'/ga-backup.sqlite';$dbBackup->create($file);$copy=new PDO('sqlite:'.$file);check($copy->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&(int)$copy->query('SELECT COUNT(*) FROM analytics_sync_runs')->fetchColumn()===4&&$copy->query('SELECT credential FROM analytics_connections')->fetchColumn()===$sealed,'backup contains encrypted credentials and durable receipts');$copy=null;
 $hub->delete($id,1);check((int)$pdo->query('SELECT COUNT(*) FROM analytics_sync_runs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM analytics_reports')->fetchColumn()===0,'delete removes reports and history');
}finally{RecoveryBundle::cleanup($work);}
