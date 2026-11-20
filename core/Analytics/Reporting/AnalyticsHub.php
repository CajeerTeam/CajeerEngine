<?php
declare(strict_types=1);
namespace CajeerEngine\Analytics\Reporting;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Queue\ManagedQueueDriverInterface;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Content\RevisionConflict;
use PDO;

/** Durable report snapshots. Credentials never leave the server or enter queue payloads. */
final readonly class AnalyticsHub
{
    public function __construct(private DatabaseManager $db, private SecretVault $vault, private ManagedQueueDriverInterface $queue, private ConfigRepository $config, private ?ReportProvider $metrika=null,private ?ReportProvider $ga4=null, private ?SearchReports $webmaster=null, private ?SearchReports $searchConsole=null, private ?ReportProvider $matomo=null, private ?ReportProvider $plausible=null, private ?ReportProvider $cfZone=null, private ?ReportProvider $cfWeb=null) {}
    private function provider(string $id): ReportProvider {return match($id){'metrika'=>$this->metrika??new ApiReports('metrika'),'ga4'=>$this->ga4??new ApiReports('ga4'),'webmaster'=>$this->webmaster??new SearchReports('webmaster'),'search_console'=>$this->searchConsole??new SearchReports('search_console'),'matomo'=>$this->matomo??new AlternativeReports('matomo'),'plausible'=>$this->plausible??new AlternativeReports('plausible'),'cf_zone'=>$this->cfZone??new CloudflareReports('cf_zone'),'cf_web'=>$this->cfWeb??new CloudflareReports('cf_web'),default=>throw new \InvalidArgumentException('Unknown analytics provider')};}
    private function enabled(): void {if(!$this->config->bool('integrations.enabled',true)||!$this->config->bool('analytics.enabled',true))throw new \InvalidArgumentException('Analytics reporting disabled by operator');}
    private function query(string $sql,array $params=[]): \PDOStatement {$s=$this->db->connection()->prepare($sql);$s->execute($params);return $s;}
    private function item(string $id): array {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid analytics connection ID');$r=$this->query('SELECT * FROM analytics_connections WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new \InvalidArgumentException('Analytics connection not found');return $r;}
    private function publicItem(array $r): array {return ['id'=>$r['id'],'provider'=>$r['provider'],'name'=>$r['name'],'resource'=>$r['resource_id'],'goal'=>$r['goal_id'],'enabled'=>(bool)$r['enabled'],'revision'=>(int)$r['revision'],'last_success'=>$r['last_success'],'last_error'=>$r['last_error'],'last_attempt'=>$r['last_attempt'],'syncing'=>(int)$r['lease_until']>time()];}
    public function overview(): array {return ['enabled'=>$this->config->bool('integrations.enabled',true)&&$this->config->bool('analytics.enabled',true),'items'=>array_map($this->publicItem(...),$this->query('SELECT * FROM analytics_connections ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC))];}
    private function context(string $id,string $provider,string $resource): string {return 'analytics:report:v1:'.$id.':'.$provider.':'.$resource;}
    private function validate(array $input): array {
        $provider=$input['provider']??'';$name=$input['name']??null;$resource=$input['resource']??null;$goal=$input['goal']??null;$enabled=$input['enabled']??null;
        if(!is_string($provider)||!is_string($name)||trim($name)===''||strlen($name)>200||preg_match('/[\x00-\x1f\x7f]/',$name)||!is_string($resource)||!(in_array($provider,['webmaster','search_console'],true)?SearchReports::validResource($provider,$resource):(in_array($provider,['cf_zone','cf_web'],true)?CloudflareReports::validResource($resource):(in_array($provider,['matomo','plausible'],true)?AlternativeReports::validResource($provider,$resource):preg_match('/^[1-9][0-9]{0,19}$/D',$resource))))||!is_bool($enabled)||($goal!==null&&($provider!=='metrika'||!is_string($goal)||!preg_match('/^[1-9][0-9]{0,19}$/D',$goal))))throw new \InvalidArgumentException('Invalid analytics connection');
        $this->provider($provider);return [$provider,trim($name),$resource,$goal,(int)$enabled];
    }
    public function save(?string $id,array $input): array {
        [$provider,$name,$resource,$goal,$enabled]=$this->validate($input);$credential=$input['credential']??null;
        if($credential!==null){if(!is_array($credential))throw new \InvalidArgumentException('Invalid credential');$this->provider($provider)->validateCredential($credential);if(in_array($provider,['matomo','plausible','cf_zone','cf_web'],true)){try{$this->hostPolicy($provider,$credential);}catch(\RuntimeException $e){throw new \InvalidArgumentException($e->getMessage());}}}
        if($id===null){if($credential===null)throw new \InvalidArgumentException('Credential required');if((int)$this->query('SELECT COUNT(*) FROM analytics_connections')->fetchColumn()>=50)throw new \InvalidArgumentException('Analytics connection limit reached');$id=bin2hex(random_bytes(16));$sealed=$this->vault->seal($this->context($id,$provider,$resource),json_encode($credential,JSON_THROW_ON_ERROR));$this->query('INSERT INTO analytics_connections (id,provider,name,resource_id,goal_id,enabled,revision,credential,lease_until) VALUES (?,?,?,?,?,?,1,?,0)',[$id,$provider,$name,$resource,$goal,$enabled,$sealed]);}
        else{$old=$this->item($id);$revision=$input['revision']??null;if(!is_int($revision)||$revision!==(int)$old['revision'])throw new RevisionConflict('Analytics connection changed');
            $changed=$credential!==null||$provider!==$old['provider']||$resource!==$old['resource_id']||$goal!==$old['goal_id'];if($credential===null&&($provider!==$old['provider']||$resource!==$old['resource_id']))throw new \InvalidArgumentException('New credential required when changing resource');
            $sealed=$credential===null?$old['credential']:$this->vault->seal($this->context($id,$provider,$resource),json_encode($credential,JSON_THROW_ON_ERROR));
            $this->db->transaction(function()use($id,$provider,$name,$resource,$goal,$enabled,$sealed,$revision,$changed){$s=$this->query('UPDATE analytics_connections SET provider=?,name=?,resource_id=?,goal_id=?,enabled=?,credential=?,revision=revision+1,lease_until=0,lease_token=NULL,last_attempt=NULL,last_error=NULL,last_job_key=NULL WHERE id=? AND revision=?',[$provider,$name,$resource,$goal,$enabled,$sealed,$id,$revision]);if($s->rowCount()!==1)throw new RevisionConflict('Analytics connection changed');$this->query("UPDATE analytics_sync_runs SET status='interrupted',finished_at=?,error='Connection configuration changed' WHERE connection_id=? AND status='running'",[gmdate(DATE_ATOM),$id]);if($changed){$this->query('DELETE FROM analytics_reports WHERE connection_id=?',[$id]);$this->query('UPDATE analytics_connections SET last_success=NULL WHERE id=?',[$id]);}});
        }return $this->publicItem($this->item($id));
    }
    public function delete(string $id,int $revision): void {$this->item($id);$this->db->transaction(function()use($id,$revision){$s=$this->query('DELETE FROM analytics_connections WHERE id=? AND revision=?',[$id,$revision]);if($s->rowCount()!==1)throw new RevisionConflict('Analytics connection changed');$this->query('DELETE FROM analytics_reports WHERE connection_id=?',[$id]);$this->query('DELETE FROM analytics_sync_runs WHERE connection_id=?',[$id]);});}
    public static function period(?string $from=null,?string $to=null): array {
        if($from===null&&$to===null){$to=gmdate('Y-m-d',time()-86400);$from=gmdate('Y-m-d',time()-30*86400);}
        foreach([$from,$to] as $d){if(!is_string($d)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)||!checkdate((int)substr($d,5,2),(int)substr($d,8,2),(int)substr($d,0,4)))throw new \InvalidArgumentException('Invalid analytics date');}
        if($from>$to||$to>gmdate('Y-m-d')||$from<'2000-01-01'||strtotime($to.' UTC')-strtotime($from.' UTC')>89*86400)throw new \InvalidArgumentException('Report period must be 1–90 days, not in future');return [$from,$to];
    }
    public function enqueue(string $id,?string $from=null,?string $to=null,bool $scheduled=false): array {
        $this->enabled();$r=$this->item($id);if(!(bool)$r['enabled'])throw new \InvalidArgumentException('Analytics connection disabled');[$from,$to]=self::period($from,$to);if(in_array($r['provider'],['cf_zone','cf_web'],true))CloudflareReports::period($from,$to);
        $bucket=intdiv(time(),$scheduled?3600:300);$job=$this->queue->push('analytics.report_sync',['id'=>$id,'revision'=>(int)$r['revision'],'from'=>$from,'to'=>$to,'_idempotency_key'=>'analytics-report:'.$id.':'.$r['revision'].':'.$from.':'.$to.':'.($scheduled?'scheduled':'manual').':'.$bucket]);return ['job_id'=>$job,'from'=>$from,'to'=>$to];
    }
    public function schedule(): array {
        if(!$this->config->bool('integrations.enabled',true)||!$this->config->bool('analytics.enabled',true))return ['queued'=>0];$n=0;
        $failed=0;
        foreach($this->query('SELECT id FROM analytics_connections WHERE enabled=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id){
            try{$this->enqueue($id,null,null,true);$n++;}catch(\Throwable){$failed++;}
        }
        // An isolated bad connection must not starve the rest of the scheduler batch.
        return ['queued'=>$n,'failed'=>$failed,'ok'=>$failed===0,'error'=>$failed?'One or more analytics jobs could not be queued':null];
    }
    private function hostPolicy(string $provider,array $credential,?string $action=null): void {
        $allowed=$this->config->get('integrations.allowed_hosts',[]);
        if(in_array($provider,['matomo','plausible'],true)){
            $host=parse_url(AlternativeReports::base($credential['endpoint']??''),PHP_URL_HOST);
            if(($allowed!==[]||$provider!=='plausible'||$host!=='plausible.io')&&!in_array($host,array_map('strtolower',$allowed),true))throw new \RuntimeException('Analytics provider host not allowed');
            return;
        }
        if($allowed===[])return;
        $hosts=match($provider){'cf_zone','cf_web'=>['api.cloudflare.com'],'metrika'=>['api-metrika.yandex.net'],'ga4'=>['analyticsdata.googleapis.com'],'webmaster'=>['api.webmaster.yandex.net'],'search_console'=>[$action==='inspect'?'searchconsole.googleapis.com':'www.googleapis.com'],default=>throw new \InvalidArgumentException('Unknown analytics provider')};if(in_array($provider,['ga4','search_console'],true)&&($credential['type']??'')!=='oauth_token')$hosts[]='oauth2.googleapis.com';
        if(array_diff($hosts,array_map('strtolower',$allowed)))throw new \RuntimeException('Analytics provider host not allowed');
    }
    public function sync(array $job): void {
        $this->enabled();$id=$job['id']??'';$revision=$job['revision']??0;[$from,$to]=self::period($job['from']??null,$job['to']??null);
        try{$r=$this->item($id);}catch(\InvalidArgumentException){return;}
        if((int)$r['revision']!==$revision||!(bool)$r['enabled'])return;
        $jobKey=$job['_idempotency_key']??null;
        if($jobKey!==null&&(!is_string($jobKey)||$jobKey===''||strlen($jobKey)>256))throw new \InvalidArgumentException('Invalid analytics job key');
        // A hash keeps queue keys out of the report API. Unkeyed internal calls are new attempts.
        $key=hash('sha256',$jobKey??bin2hex(random_bytes(32)));$lease=bin2hex(random_bytes(16));$now=time();
        $claimed=$this->db->transaction(function()use($id,$revision,$lease,$now,$key,$jobKey,$from,$to){
            $s=$this->query('UPDATE analytics_connections SET lease_token=?,lease_until=? WHERE id=? AND revision=? AND enabled=1 AND lease_until<=?',[$lease,$now+300,$id,$revision,$now]);
            if($s->rowCount()!==1){$current=$this->query('SELECT revision,enabled FROM analytics_connections WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$current||(int)$current['revision']!==$revision||!(bool)$current['enabled'])return false;throw new \RuntimeException('Analytics sync busy');}
            // Check AFTER acquiring the lease, not using the stale row read before it.
            $receipt=$this->query('SELECT status,date_from,date_to FROM analytics_sync_runs WHERE connection_id=? AND revision=? AND job_key=?',[$id,$revision,$key])->fetch(PDO::FETCH_ASSOC);
            $last=$this->query('SELECT last_job_key FROM analytics_connections WHERE id=?',[$id])->fetchColumn();
            if($receipt&&($receipt['date_from']!==$from||$receipt['date_to']!==$to))throw new \InvalidArgumentException('Analytics job key reused for a different period');
            if(($receipt['status']??null)==='success'||($jobKey!==null&&$last===$jobKey)){
                $this->query('UPDATE analytics_connections SET lease_token=NULL,lease_until=0 WHERE id=? AND lease_token=?',[$id,$lease]);return false;
            }
            // A crashed previous worker is visible as interrupted, not permanently running.
            $this->query("UPDATE analytics_sync_runs SET status='interrupted',finished_at=?,error='Worker lease expired' WHERE connection_id=? AND revision=? AND status='running'",[gmdate(DATE_ATOM,$now),$id,$revision]);
            if($receipt)$this->query("UPDATE analytics_sync_runs SET status='running',attempts=attempts+1,started_at=?,finished_at=NULL,error=NULL WHERE connection_id=? AND revision=? AND job_key=?",[gmdate(DATE_ATOM,$now),$id,$revision,$key]);
            else $this->query("INSERT INTO analytics_sync_runs (connection_id,revision,job_key,date_from,date_to,status,attempts,started_at) VALUES (?,?,?,?,?,'running',1,?)",[$id,$revision,$key,$from,$to,gmdate(DATE_ATOM,$now)]);
            $this->query('UPDATE analytics_connections SET last_attempt=? WHERE id=? AND lease_token=?',[gmdate(DATE_ATOM,$now),$id,$lease]);return true;
        });
        if(!$claimed)return;
        try{
            $credential=json_decode($this->vault->open($this->context($id,$r['provider'],$r['resource_id']),$r['credential']),true,32,JSON_THROW_ON_ERROR);
            $this->hostPolicy($r['provider'],$credential);
            $report=$this->provider($r['provider'])->report($r['resource_id'],$credential,$from,$to,$r['goal_id']);
            $json=json_encode($report,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
            if(strlen($json)>4194304)throw new \RuntimeException('Analytics report exceeds storage limit');
            $this->db->transaction(function()use($id,$revision,$lease,$from,$to,$report,$json,$jobKey,$key){
                $s=$this->query('UPDATE analytics_connections SET lease_until=0,lease_token=NULL,last_success=?,last_error=NULL,last_job_key=? WHERE id=? AND revision=? AND lease_token=?',[$report['fetched_at'],$jobKey,$id,$revision,$lease]);if($s->rowCount()!==1)return;
                $this->query('DELETE FROM analytics_reports WHERE connection_id=? AND date_from=? AND date_to=?',[$id,$from,$to]);
                $this->query('INSERT INTO analytics_reports (connection_id,date_from,date_to,fetched_at,report_json) VALUES (?,?,?,?,?)',[$id,$from,$to,$report['fetched_at'],$json]);
                $this->query("UPDATE analytics_sync_runs SET status='success',finished_at=?,error=NULL WHERE connection_id=? AND revision=? AND job_key=?",[gmdate(DATE_ATOM),$id,$revision,$key]);
                $rows=$this->query('SELECT date_from,date_to FROM analytics_reports WHERE connection_id=? ORDER BY fetched_at DESC,date_from DESC,date_to DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);
                foreach(array_slice($rows,20) as $row)$this->query('DELETE FROM analytics_reports WHERE connection_id=? AND date_from=? AND date_to=?',[$id,$row['date_from'],$row['date_to']]);
                $this->trimRuns($id);
            });
        }catch(\Throwable $e){
            $msg=preg_match('/^Analytics provider HTTP [0-9]{3}$/D',$e->getMessage())||in_array($e->getMessage(),['Analytics provider host not allowed','Analytics transport unavailable','Invalid analytics response','Invalid OAuth response','Cloudflare GraphQL query rejected','Cloudflare dataset unavailable','Analytics report exceeds storage limit'],true)?$e->getMessage():'Analytics synchronization failed';
            $this->db->transaction(function()use($id,$revision,$lease,$msg,$key){
                $s=$this->query('UPDATE analytics_connections SET lease_until=0,lease_token=NULL,last_error=? WHERE id=? AND revision=? AND lease_token=?',[$msg,$id,$revision,$lease]);
                if($s->rowCount()===1){$this->query("UPDATE analytics_sync_runs SET status='failed',finished_at=?,error=? WHERE connection_id=? AND revision=? AND job_key=?",[gmdate(DATE_ATOM),$msg,$id,$revision,$key]);$this->trimRuns($id);}
            });throw new \RuntimeException($msg);
        }
    }
    private function trimRuns(string $id): void {
        $rows=$this->query('SELECT revision,job_key FROM analytics_sync_runs WHERE connection_id=? ORDER BY started_at DESC,revision DESC,job_key DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach(array_slice($rows,500) as $row)$this->query('DELETE FROM analytics_sync_runs WHERE connection_id=? AND revision=? AND job_key=?',[$id,$row['revision'],$row['job_key']]);
    }
    public function webmasterAction(string $id,int $revision,string $action,string $url): array {
        $this->enabled();$r=$this->item($id);
        if((int)$r['revision']!==$revision)throw new RevisionConflict('Analytics connection changed');
        if(!(bool)$r['enabled']||!in_array($r['provider'],['webmaster','search_console'],true))throw new \InvalidArgumentException('Active search connection required');
        $provider=$this->provider($r['provider']);
        $credential=json_decode($this->vault->open($this->context($id,$r['provider'],$r['resource_id']),$r['credential']),true,32,JSON_THROW_ON_ERROR);
        $this->hostPolicy($r['provider'],$credential,$action);
        if (!$provider instanceof SearchReports) throw new \RuntimeException('Invalid search reporting backend');
        return $provider->action($r['resource_id'],$credential,$action,$url);
    }
    public function report(string $id,?string $from=null,?string $to=null): array {
        $r=$this->item($id);$params=[$id];$sql='SELECT report_json FROM analytics_reports WHERE connection_id=?';if($from!==null||$to!==null){[$from,$to]=self::period($from,$to);$sql.=' AND date_from=? AND date_to=?';$params[]=$from;$params[]=$to;}$json=$this->query($sql.' ORDER BY fetched_at DESC,date_from DESC LIMIT 1',$params)->fetchColumn();
        $periods=$this->query('SELECT date_from,date_to,fetched_at FROM analytics_reports WHERE connection_id=? ORDER BY fetched_at DESC,date_from DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);$snapshot=$json===false?null:json_decode($json,true,64,JSON_THROW_ON_ERROR);
        $runs=$this->query('SELECT date_from,date_to,status,attempts,started_at,finished_at,error FROM analytics_sync_runs WHERE connection_id=? AND revision=? ORDER BY started_at DESC,job_key DESC LIMIT 20',[$id,$r['revision']])->fetchAll(PDO::FETCH_ASSOC);
        foreach($runs as &$run){$run['attempts']=(int)$run['attempts'];if($run['status']==='running'&&(int)$r['lease_until']<=time()){$run['status']='interrupted';$run['error']='Worker lease expired';}}unset($run);
        $comparison=null;if($snapshot!==null){$previous=ReportComparison::previous($snapshot['from'],$snapshot['to']);if($previous!==null){[$pf,$pt]=$previous;$raw=$this->query('SELECT report_json FROM analytics_reports WHERE connection_id=? AND date_from=? AND date_to=?',[$id,$pf,$pt])->fetchColumn();$comparison=ReportComparison::compare($snapshot,$raw===false?null:json_decode($raw,true,64,JSON_THROW_ON_ERROR),$pf,$pt);}}
        // Do not pair an old connection with a new resource's snapshots during a concurrent edit.
        $current=$this->item($id);if((int)$current['revision']!==(int)$r['revision'])throw new RevisionConflict('Analytics connection changed');
        return ['connection'=>$this->publicItem($current),'report'=>$snapshot,'periods'=>$periods,'comparison'=>$comparison,'sync_history'=>$runs];
    }
}
