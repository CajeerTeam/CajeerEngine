<?php
declare(strict_types=1);
namespace CajeerEngine\Migration;

use CajeerEngine\Content\RevisionConflict;
use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;

/** Small, durable discovery projects. Network I/O is outside the registry lock; CAS prevents stale reports. */
final readonly class MigrationCenter
{
    public function __construct(private string $root,private SecretVault $vault,private SourceDiscovery $discovery=new SourceDiscovery(),private WxrDiscovery $wxr=new WxrDiscovery(),private ?MigrationPlanner $planner=null,private ?DleImporter $dle=null,private ?MediaMigration $media=null,private ?SeoMigration $seo=null,private ?MigrationExecution $execution=null) {}
    private function path(string $id):string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Invalid migration project ID.');return F::path($this->root,'storage/app/migration/'.$id.'.json');}
    private function locked(callable $fn,bool $exclusive=false):mixed {
        if($exclusive&&!OperationLock::exclusiveHeld($this->root))throw new \LogicException('Exclusive migration lease required.');
        $lease=$exclusive?null:OperationLock::acquire($this->root,false);
        try{
            $dir=F::path($this->root,'storage/app/migration');F::dir($dir,0700);$lock=F::path($this->root,'storage/app/migration/projects.lock');$h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Cannot lock migration projects.');
            try{F::permissions($lock,0600);if(!flock($h,LOCK_EX))throw new \RuntimeException('Cannot lock migration projects.');return $fn($dir);}finally{fclose($h);}
        }finally{OperationLock::release($lease);}
    }
    private function get(string $id):array {$p=$this->path($id);if(!is_file($p))throw new \RuntimeException('Проект миграции не найден.');return F::read($p);}
    private function public(array $p):array {if(($e=$this->executionStatus($p['id']))!==null)$p['batch_execution']=$e;unset($p['credential'],$p['wxr_ref']);if($p['status']==='approved'&&($receipt=$this->dle?->receipt($p['id'],$p['source']))!==null){$p['execution']=$receipt;$p['status']='completed';if($this->media!==null)$p['media_migration']=$this->media->report($p['id']);if($this->seo!==null)$p['seo_migration']=$this->seo->report($p['id']);}return $p;}
    private function executionStatus(string $id):?array
    {
        if($this->execution===null)return null;
        try{return $this->execution->status($id);}catch(\CajeerEngine\Database\DatabaseUnavailable|\PDOException){return null;}
    }
    private function wxrPath(string $id,string $ref):string {if(!preg_match('/^[a-f0-9]{32}$/D',$ref))throw new \RuntimeException('Invalid WXR storage reference.');$this->path($id);return F::path($this->root,'storage/app/migration/'.$id.'-'.$ref.'.wxr.enc');}
    private function cleanupWxr(string $id,?string $keep=null):void {foreach(glob(dirname($this->path($id)).'/'.$id.'-*.wxr.enc')?:[] as $f){if($keep!==null&&$f===$this->wxrPath($id,$keep))continue;F::remove($f);}}
    public function list():array {return $this->locked(function($dir){$out=[];foreach(glob($dir.'/*.json')?:[] as $f){$id=basename($f,'.json');$out[]=$this->public($this->get($id));}usort($out,fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));return ['projects'=>$out];});}
    public function show(string $id):array{return $this->locked(fn()=> $this->public($this->get($id)));}
    public function create(array $body):array{return $this->locked(function($dir)use($body){if(count(glob($dir.'/*.json')?:[])>=25)throw new \RuntimeException('Migration project limit reached (25).');$id=bin2hex(random_bytes(16));$p=$this->configure($id,$body);$p['revision']=1;$p['created_at']=$p['updated_at']=gmdate(DATE_ATOM);F::json($this->path($id),$p);return $this->public($p);});}
    public function update(string $id,array $body):array{return $this->locked(function()use($id,$body){$old=$this->get($id);$this->revision($old,$body['revision']??null);$p=$this->configure($id,$body);$p['revision']=$old['revision']+1;$p['created_at']=$old['created_at'];$p['updated_at']=gmdate(DATE_ATOM);F::json($this->path($id),$p);$this->cleanupWxr($id);return $this->public($p);});}
    public function delete(string $id,int $revision):void{$this->locked(function()use($id,$revision){$this->revision($this->get($id),$revision);F::remove($this->path($id));$this->cleanupWxr($id);});}
    private function revision(array $p,mixed $revision):void {if($this->executionStatus($p['id'])!==null)throw new RevisionConflict('Checkpointed migration is immutable; complete or restore first.');if($p['status']==='approved'&&$this->dle?->receipt($p['id'],$p['source'])!==null)throw new RevisionConflict('Completed migration is immutable; create a new project for a different import.');if(!is_int($revision)||$revision!==$p['revision'])throw new RevisionConflict('Migration project has changed; reload it.');}
    private function configure(string $id,array $b):array {
        $name=trim((string)($b['name']??''));if($name===''||strlen($name)>150||!preg_match('//u',$name))throw new \InvalidArgumentException('Project name must contain 1–150 bytes.');
        $source=$b['source']??'';$config=$b['config']??[];$secret=$b['credential']??[];
        if(!is_array($config)||!is_array($secret)||!in_array($source,['dle-db','wordpress-db','wordpress-rest','wordpress-wxr'],true))throw new \InvalidArgumentException('Invalid migration source.');
        if($source==='wordpress-wxr'){$config=[];$secret=[];}
        elseif($source==='wordpress-rest'){$config=SourceDiscovery::restConfig($config);$secret=$this->credential($secret,false);}
        else{$config=SourceDiscovery::dbConfig($config);$secret=$this->credential($secret,true);}
        return ['id'=>$id,'name'=>$name,'source'=>$source,'config'=>$config,'credential'=>$this->vault->seal('migration:v1:'.$id.':'.$source,json_encode($secret,JSON_THROW_ON_ERROR)),'status'=>'draft','report'=>null,'error'=>null];
    }
    private function credential(array $c,bool $required):array {
        $u=$c['username']??'';$p=$c['password']??'';
        if(!is_string($u)||!is_string($p)||strlen($u)>190||strlen($p)>4096||preg_match('/[\x00-\x1f\x7f]/',$u.$p)||str_contains($u,':')||($required&&($u===''||$p===''))||(($u==='')!==($p==='')))throw new \InvalidArgumentException('Invalid source credentials.');
        return ['username'=>$u,'password'=>$p];
    }
    private function planning():MigrationPlanner {return $this->planner??throw new \RuntimeException('Migration planning is unavailable.');}
    public function catalog():array{return $this->planning()->catalog();}
    public function saveMapping(string $id,int $revision,array $mapping):array {
        $mapping=MigrationPlanner::mapping($mapping);
        return $this->locked(function()use($id,$revision,$mapping){$p=$this->get($id);$this->revision($p,$revision);if(!$p['report']||$p['error']!==null)throw new \InvalidArgumentException('A successful discovery is required.');$p['mapping']=$mapping;unset($p['plan']);$p['status']='mapped';$p['revision']++;$p['updated_at']=gmdate(DATE_ATOM);F::json($this->path($id),$p);return $this->public($p);});
    }
    private function planningSnapshot(string $id,int $revision):array {
        return $this->locked(function()use($id,$revision){$p=$this->get($id);$this->revision($p,$revision);if(!$p['report']||$p['error']!==null||!isset($p['mapping']))throw new \InvalidArgumentException('Save a mapping after successful discovery.');if($p['source']==='wordpress-wxr'){$p['wxr']=file_get_contents($this->wxrPath($id,$p['wxr_ref']??''));if($p['wxr']===false)throw new \RuntimeException('Stored WXR is unavailable.');}return $p;});
    }
    /** Operator-only; does not expose credentials or decrypted WXR to HTTP callers. */
    public function mediaSourceMetadata(string $id):array
    {
        if(!OperationLock::exclusiveHeld($this->root))throw new \LogicException('Exclusive operator lease required.');
        return $this->locked(function()use($id){
            $p=$this->get($id);if(!str_starts_with($p['source'],'wordpress-'))return ['attachments'=>[],'featured'=>[]];
            if($p['source']==='wordpress-wxr'){
                $file=$this->wxrPath($id,(string)($p['wxr_ref']??''));
                $sealed=file_get_contents($file);if($sealed===false)throw new \RuntimeException('WXR metadata source is unavailable.');
                return WordPressMediaMetadata::fromWxr($this->vault->open('migration:wxr:v1:'.$id,$sealed));
            }
            $secret=json_decode($this->vault->open('migration:v1:'.$id.':'.$p['source'],$p['credential']),true,16,JSON_THROW_ON_ERROR);
            return $this->discovery->wordpressMediaMetadata($p['source'],$p['config'],$secret);
        },true);
    }
    /** Operator-only fresh SEO inventory; source credentials and the raw WXR never enter API responses. */
    public function seoSourceInventory(string $id):array
    {
        if(!OperationLock::exclusiveHeld($this->root))throw new \LogicException('Exclusive operator lease required.');
        return $this->locked(function()use($id){
            $p=$this->get($id);
            if($p['source']==='wordpress-wxr'){
                $file=$this->wxrPath($id,(string)($p['wxr_ref']??''));
                $sealed=file_get_contents($file);
                if($sealed===false)throw new \RuntimeException('Original WXR export is unavailable.');
                $p['wxr']=$sealed;
            }
            return $this->inventory($p);
        },true);
    }
    private function calculate(array $p):array {
        return $this->planning()->plan($this->inventory($p),$p['mapping']);
    }
    private function inventory(array $p):array {
        $id=$p['id'];if($p['source']==='wordpress-wxr'){$xml=$this->vault->open('migration:wxr:v1:'.$id,$p['wxr']);$inventory=(new PlanningSource())->wxr($xml);}else{$secret=json_decode($this->vault->open('migration:v1:'.$id.':'.$p['source'],$p['credential']),true,16,JSON_THROW_ON_ERROR);$inventory=$this->discovery->inventory($p['source'],$p['config'],$secret);}
        return $inventory;
    }
    public function dryRun(string $id,int $revision):array {
        $p=$this->planningSnapshot($id,$revision);$plan=$this->calculate($p);
        return $this->locked(function()use($id,$revision,$plan){$p=$this->get($id);$this->revision($p,$revision);$p['plan']=$plan+['created_at'=>gmdate(DATE_ATOM),'approved_at'=>null];$p['status']='planned';$p['revision']++;$p['updated_at']=gmdate(DATE_ATOM);F::json($this->path($id),$p);return $this->public($p);});
    }
    public function approve(string $id,int $revision,string $sha256):array {
        $p=$this->planningSnapshot($id,$revision);if(!isset($p['plan'])||!$p['plan']['approvable']||!hash_equals($p['plan']['sha256'],$sha256))throw new \InvalidArgumentException('Plan is missing, has unresolved issues or hash does not match.');
        $fresh=$this->calculate($p);if(!hash_equals($sha256,$fresh['sha256']))throw new RevisionConflict('Source or target changed; run Dry Run again.');
        return $this->locked(function()use($id,$revision,$sha256){$p=$this->get($id);$this->revision($p,$revision);if(!hash_equals($p['plan']['sha256'],$sha256))throw new RevisionConflict('Plan has changed.');$p['plan']['approved_at']=gmdate(DATE_ATOM);$p['status']='approved';$p['revision']++;$p['updated_at']=gmdate(DATE_ATOM);F::json($this->path($id),$p);return $this->public($p);});
    }
    /** Return credentials only to an operator process holding the global exclusive lease. */
    public function executionSnapshot(string $id):array
    {
        if(!OperationLock::exclusiveHeld($this->root))throw new \LogicException('Exclusive execution lease required.');
        return $this->locked(function()use($id){
            $p=$this->get($id);
            if($p['source']==='wordpress-wxr'){
                $raw=file_get_contents($this->wxrPath($id,(string)($p['wxr_ref']??'')));
                if($raw===false)throw new \RuntimeException('Stored WXR unavailable.');
                $p['wxr']=$raw;
            }
            return ['project'=>$p,'inventory'=>$this->inventory($p)];
        },true);
    }
    /** Operator-only execution; the exclusive lease drains HTTP and CLI writers before source revalidation. */
    public function executeDle(string $id,string $sha256):array {return $this->executeContent($id,$sha256,false);}
    public function executeWordPress(string $id,string $sha256):array {return $this->executeContent($id,$sha256,true);}
    private function executeContent(string $id,string $sha256,bool $wp):array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha256))throw new \InvalidArgumentException('Valid approved plan SHA-256 required.');
        $executor=$this->dle??throw new \RuntimeException('DLE execution is unavailable.');
        $lease=OperationLock::acquire($this->root,true);
        try{
            if(OperationLock::maintenance($this->root)||is_file(F::path($this->root,'storage/app/backup-restore.json'))||is_file(F::path($this->root,'storage/app/extensions/deployment.json')))throw new \RuntimeException('Recovery or deployment is pending; finish it before import.');
            return $this->locked(function()use($id,$sha256,$executor,$wp){
            $p=$this->get($id);
            if(($p['source']!=='dle-db')!==$wp)throw new \InvalidArgumentException('Migration source does not match command.');
            if($prior=$executor->receipt($id,$p['source'])){if(!hash_equals($prior['plan_sha256'],$sha256))throw new RevisionConflict('Project already executed with a different plan.');return $prior;}
            if($p['status']!=='approved'||empty($p['plan']['approved_at'])||!hash_equals($p['plan']['sha256']??'',$sha256))throw new RevisionConflict('Use the current approved plan SHA-256.');
            if($p['source']==='wordpress-wxr'){$p['wxr']=file_get_contents($this->wxrPath($id,$p['wxr_ref']??''));if($p['wxr']===false)throw new \RuntimeException('Stored WXR is unavailable.');}
            $inventory=$this->inventory($p);
            return $executor->execute($p,$inventory);
        },true);}finally{OperationLock::release($lease);}
    }
    public function scan(string $id,int $revision,#[\SensitiveParameter] ?string $xml=null):array {
        $p=$this->locked(function()use($id,$revision,$xml){$p=$this->get($id);$this->revision($p,$revision);if($xml===null&&isset($p['wxr_ref'])&&$p['source']==='wordpress-wxr'){$path=$this->wxrPath($id,$p['wxr_ref']);$p['wxr']=file_get_contents($path);if($p['wxr']===false)throw new \RuntimeException('Stored WXR is unavailable.');}return $p;});
        if($xml!==null&&$p['source']!=='wordpress-wxr')throw new \InvalidArgumentException('WXR upload requires a WordPress WXR project.');
        $sealed=null;
        try{
            if($p['source']==='wordpress-wxr'){
                if($xml===null){if(!isset($p['wxr']))throw new \InvalidArgumentException('Upload a WXR export first.');$xml=$this->vault->open('migration:wxr:v1:'.$id,$p['wxr']);}
                $report=$this->wxr->scan($xml);$sealed=$this->vault->seal('migration:wxr:v1:'.$id,$xml);
            }else{$secret=json_decode($this->vault->open('migration:v1:'.$id.':'.$p['source'],$p['credential']),true,16,JSON_THROW_ON_ERROR);$report=$this->discovery->scan($p['source'],$p['config'],$secret);}
            $error=null;
        }catch(\Throwable $e){$report=null;$error=$e instanceof \InvalidArgumentException||($e instanceof \RuntimeException&&!$e instanceof \PDOException)?$e->getMessage():'Source discovery failed.';}
        return $this->locked(function()use($id,$revision,$report,$sealed,$error){$p=$this->get($id);$this->revision($p,$revision);$p['revision']++;$p['updated_at']=gmdate(DATE_ATOM);$p['status']=$error===null?'discovered':'failed';$p['error']=$error;unset($p['plan']);if($report!==null){$p['report']=$report;if($sealed!==null){$ref=bin2hex(random_bytes(16));F::write($this->wxrPath($id,$ref),$sealed);$p['wxr_ref']=$ref;}}F::json($this->path($id),$p);$this->cleanupWxr($id,$p['wxr_ref']??null);return $this->public($p);});
    }
}
