<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

use CajeerEngine\Backup\BackupManager;
use CajeerEngine\Backup\DatabaseBackup;
use CajeerEngine\Release\ReleaseArtifactVerifier;
use CajeerEngine\Release\ReleaseFilePolicy;
use CajeerEngine\Runtime\ConfigRepository;

/** Single-node updater. All mutations are journalled under one exclusive activity lease. */
final class UpdateManager
{
    public function __construct(private readonly string $rootPath, private readonly ConfigRepository $config) {}

    public function check(): array
    {
        $current=$this->currentVersion();
        $check=(new UpdateResolver($this->config->get('updates',[]),$this->rootPath))->check($current);
        return $check+['ok'=>true,'current_version'=>$current,'latest_version'=>$check['latest']['version']??null,'maintenance'=>$this->maintenanceState(),'production_apply_enabled'=>$this->trustConfigured(),'safe_update_command'=>'php bin/cajeer update:apply --package=/path/release-dist.zip'];
    }
    public function plan(array $options=[]): array
    {
        $package=$this->option($options,'package'); $check=$this->check();
        $plan=['ok'=>true,'current_version'=>$this->currentVersion(),'target_version'=>$this->option($options,'version')??($check['latest']['version']??null),'package'=>$package,'mode'=>'dist','backup_before_update'=>true,'code_snapshot_before_update'=>true,'maintenance_mode_during_apply'=>true,'migrations_after_apply'=>true,'post_update_doctor'=>true,'production_apply_enabled'=>$this->trustConfigured(),'update_available'=>$check['update_available']??false,'checked_at'=>gmdate(DATE_ATOM)];
        if ($package!==null && PHP_SAPI==='cli') {
            $verified=$this->verifyPackage($this->resolvePackage($package),$this->option($options,'sha256'),$this->option($options,'signature'));
            if (!$verified['ok']) throw new \RuntimeException($verified['error']);
            $plan['target_version']=$verified['version']; $plan['expected_sha256']=$verified['sha256']; $plan['file_count']=$verified['file_count'];
        }
        return $plan;
    }
    public function prepare(array $options=[]): array
    {
        $plan=$this->plan($options); $dir=$this->path('plans'); OperationFiles::dir($dir,0770);
        $path=$dir.'/plan-'.bin2hex(random_bytes(12)).'.json'; OperationFiles::json($path,$plan);
        return $plan+['plan_file'=>$path];
    }
    public function verifyPackage(string $packagePath, ?string $expectedSha=null, ?string $signature=null): array
    {
        try {
            if (!is_file($packagePath)||is_link($packagePath)||filesize($packagePath)>2*1024*1024*1024) throw new \RuntimeException('Invalid or oversized ZIP');
            $trust=$this->trustedKey();
            $sig=ReleaseSignature::verify($packagePath,$signature??$packagePath.'.sig',$trust,$expectedSha);
            $zip=new \PharData($packagePath); $name='cajeerengine/release-manifest.json';
            if (!isset($zip[$name])||$zip[$name]->getSize()>16*1024*1024) throw new \RuntimeException('Missing manifest');
            $m=json_decode($zip[$name]->getContent(),true,512,JSON_THROW_ON_ERROR);
            $version=$m['version']??''; $mode=$m['mode']??'';
            if (!is_string($version)||!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D',$version)||!in_array($mode,['source','dist'],true)) throw new \RuntimeException('Invalid release identity');
            $m=(new ReleaseArtifactVerifier())->verify($packagePath,$mode,$version);
            foreach ($m['removed_files']??[] as $file) if (!is_string($file)||!$this->managed($file)||in_array($file,$m['files'],true)) throw new \RuntimeException('Invalid removed file');
            if (version_compare(PHP_VERSION,'8.4.1','<')||version_compare(PHP_VERSION,'9.0','>=')) throw new \RuntimeException('Unsupported PHP runtime');
            return ['ok'=>true,'package'=>$packagePath,'version'=>$version,'mode'=>$mode,'manifest'=>$m,'file_count'=>count($m['files'])]+$sig;
        } catch (\Throwable $e) { return ['ok'=>false,'error'=>$e->getMessage()]; }
    }
    public function apply(array $options=[]): array
    {
        $this->cliOnly();
        foreach (['no_backup','no_maintenance','no_migrations','no_doctor'] as $flag) if (!empty($options[$flag])) throw new \RuntimeException('Safety steps cannot be disabled: '.$flag);
        $package=$this->option($options,'package')??throw new \RuntimeException('Provide --package');
        $package=$this->resolvePackage($package); $signature=$this->option($options,'signature')??$package.'.sig';
        $lock=OperationLock::acquire($this->rootPath,true);
        try {
            if (OperationLock::active($this->rootPath)||is_file($this->rootPath.'/storage/app/backup-restore.json')||is_file($this->rootPath.'/storage/app/extensions/deployment.json')) throw new \RuntimeException('Interrupted operation: recover first');
            $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)); $dir=$this->transactionDir($id); OperationFiles::dir($dir); chmod($dir,0700);
            // Copy first, then authenticate the exact bytes that will be staged.
            OperationFiles::copy($package,$dir.'/package.zip'); OperationFiles::copy($signature,$dir.'/package.zip.sig');
            $v=$this->verifyPackage($dir.'/package.zip',$this->option($options,'sha256'));
            if (!$v['ok']) throw new \RuntimeException($v['error']);
            if ($v['mode']!=='dist') throw new \RuntimeException('Only production dist artifacts can be applied');
            $previous=$this->currentVersion();
            if (version_compare($v['version'],$previous,'<=')) throw new \RuntimeException('Target must be newer; use verified rollback for downgrade');
            if (!is_file($this->rootPath.'/storage/app/installed.lock')) throw new \RuntimeException('Installed CMS required');
            $plan=$this->stage($dir,$v['manifest']);
            $report=['ok'=>true,'id'=>$id,'previous_version'=>$previous,'target_version'=>$v['version'],'sha256'=>$v['sha256'],'copy_plan'=>$plan,'dry_run'=>!empty($options['dry_run'])];
            if ($report['dry_run']) return $report;
            $backup=new BackupManager($this->rootPath,$this->config);
            (new DatabaseBackup($this->rootPath,$this->config))->assertRecoveryReady();
            $this->postCheck(false);
            $journal=['id'=>$id,'state'=>'prepared','previous_version'=>$previous,'target_version'=>$v['version'],'sha256'=>$v['sha256'],'maintenance_was_enabled'=>$this->maintenanceState()['enabled'],'maintenance_before'=>is_file($this->rootPath.'/storage/app/maintenance.json')?OperationFiles::read($this->rootPath.'/storage/app/maintenance.json'):null,'backup'=>null,'child_lock'=>'child-'.bin2hex(random_bytes(8)).'.lock','at'=>gmdate(DATE_ATOM)];
            $this->journal($journal);
            DatabaseBackup::useProcessLock($dir.'/'.$journal['child_lock']);
            try {
                $this->setMaintenance('Updating to '.$v['version']);
                $journal['state']='backup'; $this->journal($journal);
                $journal['backup']=$backup->capture()['id'];
                $journal['state']='backed_up'; $this->journal($journal);
                // Recovery code is copied from the old release before any replacement.
                $this->recoveryLauncher($dir,$backup->directory($journal['backup']));
                $journal['state']='applying'; $this->journal($journal);
                foreach ($plan['files'] as $relative) {
                    $dest=OperationFiles::path($this->rootPath,$relative);
                    $mode=$relative==='bin/cajeer'?0755:(is_file($dest)?fileperms($dest)&0777:0644);
                    OperationFiles::copy(OperationFiles::path($dir,'stage/'.$relative),$dest,$mode,is_file($dest)?fileowner($dest):null,is_file($dest)?filegroup($dest):null);
                    if (!hash_equals($v['manifest']['checksums'][$relative],OperationFiles::hash($dest))) throw new \RuntimeException('Post-copy checksum mismatch');
                }
                foreach ($plan['removed_files'] as $relative) OperationFiles::remove(OperationFiles::path($this->rootPath,$relative));
                OperationFiles::json($this->rootPath.'/release-manifest.json',$v['manifest'],0644);
                $journal['state']='migrating'; $this->journal($journal);
                $this->clearCaches(); $this->postCheck(true);
                $journal['state']='committed'; $this->journal($journal);
                $result=$report+['applied_files'=>$plan['files'],'removed_files'=>$plan['removed_files'],'backup'=>['id'=>$journal['backup']],'state'=>'committed'];
                $this->finish($journal,$result);
                return $result;
            } catch (\Throwable $e) {
                $journal['failure']='Update failed: '.$e->getMessage();
                try {
                    if ($journal['backup']!==null && in_array($journal['state'],['applying','migrating','committed'],true)) {
                        $journal['state']='recovering'; $this->journal($journal);
                        $backup->restoreCaptured($journal['backup']); $this->clearCaches(); $this->postCheck(false);
                    }
                    $journal['state']='rolled_back'; $this->journal($journal);
                    $result=['ok'=>false,'id'=>$id,'state'=>'rolled_back','error'=>$journal['failure'],'backup'=>['id'=>$journal['backup']]];
                    $this->finish($journal,$result); return $result;
                } catch (\Throwable $recovery) {
                    $journal['state']='recovery_required'; $journal['recovery_error']=$recovery->getMessage(); $this->journal($journal);
                    $this->report(['ok'=>false,'id'=>$id,'state'=>'recovery_required','error'=>'Automatic recovery failed; maintenance remains enabled','backup'=>['id'=>$journal['backup']]]);
                    throw new \RuntimeException('Recovery required; run update:rollback under maintenance: '.$recovery->getMessage());
                }
            }
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($lock); }
    }
    public function rollback(?string $backup=null, bool $confirmed=false, ?string $expectedTransaction=null): array
    {
        $this->cliOnly(); $lock=OperationLock::acquire($this->rootPath,true); $child=null;
        try {
            $active=OperationLock::active($this->rootPath);
            if ($active) $j=OperationFiles::read($this->path('active.json'));
            else {
                if (!$confirmed) throw new \RuntimeException('Rollback after a committed update discards subsequent writes; pass --yes');
                $last=OperationFiles::read($this->path('last-update.json'));
                if (($last['state']??null)!=='committed') throw new \RuntimeException('No committed update to roll back');
                $j=OperationFiles::read($this->transactionDir($last['id']).'/journal.json');
                if ($this->currentVersion()!==$j['target_version']) throw new \RuntimeException('Current version does not match rollback target');
            }
            if ($expectedTransaction!==null&&$expectedTransaction!==$j['id']) throw new \RuntimeException('Stale rollback request');
            if ($backup!==null && $backup!==($j['backup']??null)) throw new \RuntimeException('Backup must match active/latest update');
            $dir=$this->transactionDir($j['id']);
            if (isset($j['child_lock'])) { if (!preg_match('/^child-[a-f0-9]{16}\.lock$/D',$j['child_lock'])) throw new \RuntimeException('Invalid child lease'); $child=OperationLock::childGuard($dir.'/'.$j['child_lock']); }
            $j['child_lock']='child-'.bin2hex(random_bytes(8)).'.lock';
            $this->journal($j); DatabaseBackup::useProcessLock($dir.'/'.$j['child_lock']);
            $this->setMaintenance('Recovering update');
            $state=$j['state'];
            if (!in_array($state,['prepared','backup','backed_up'],true)) {
                if (empty($j['backup'])) throw new \RuntimeException('Missing recovery backup');
                $j['state']='recovering'; $this->journal($j);
                (new BackupManager($this->rootPath,$this->config))->restoreCaptured($j['backup']);
                $this->clearCaches(); $this->postCheck(false);
            }
            $j['state']='rolled_back'; $this->journal($j);
            $result=['ok'=>true,'id'=>$j['id'],'state'=>'rolled_back','version'=>$j['previous_version'],'backup'=>['id'=>$j['backup']??null]];
            $this->finish($j,$result); return $result;
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($child); OperationLock::release($lock); }
    }
    public function maintenanceState(): array
    {
        $p=$this->rootPath.'/storage/app/maintenance.json';
        $data=is_file($p)?json_decode((string)file_get_contents($p),true):[];
        return ['enabled'=>OperationLock::maintenance($this->rootPath),'file'=>$p,'update_active'=>OperationLock::active($this->rootPath)]+(is_array($data)?$data:[]);
    }
    public function enableMaintenance(string $message='Maintenance'): array { $this->setMaintenance($message); return ['ok'=>true]+$this->maintenanceState(); }
    public function disableMaintenance(): array
    {
        if (is_file($this->rootPath.'/storage/app/extensions/deployment.json')) throw new \RuntimeException('Recover extension deployment first');
        // HTTP holds a shared lease. The active marker blocks reopening after a crash.
        if (OperationLock::active($this->rootPath)||is_file($this->rootPath.'/storage/app/backup-restore.json')) throw new \RuntimeException('Cannot leave maintenance while recovery is pending');
        OperationFiles::remove($this->rootPath.'/storage/app/maintenance.json'); return ['ok'=>true,'enabled'=>false];
    }
    public function wizardState(): array
    {
        $last=PHP_SAPI==='cli'?$this->path('last-update.json'):$this->requestDir().'/last-report.json';
        $report=is_file($last)?OperationFiles::read($last):null;
        return ['ok'=>true,'current_version'=>$this->currentVersion(),'maintenance'=>$this->maintenanceState(),'last_report'=>$report===null?null:array_intersect_key($report,array_flip(['ok','id','state','previous_version','target_version','version'])),'installed'=>is_file($this->rootPath.'/storage/app/installed.lock'),'production_apply_enabled'=>$this->trustConfigured()];
    }
    public function staged(): array
    {
        $dir=$this->requestDir(); $file=$dir.'/staged.json';
        return is_file($file)?OperationFiles::read($file):[];
    }
    /** CLI places and verifies a signed artifact before a browser can request it. */
    public function publish(string $package, ?string $signature=null): array
    {
        $this->cliOnly(); $package=$this->resolvePackage($package); $lock=OperationLock::acquire($this->rootPath,true);
        try {
            if (OperationLock::active($this->rootPath)||is_file($this->rootPath.'/storage/app/backup-restore.json')) throw new \RuntimeException('Recovery pending');
            $id=bin2hex(random_bytes(16)); $dir=$this->path('inbox/'.$id); OperationFiles::dir($dir); chmod($dir,0700);
            OperationFiles::copy($package,$dir.'/package.zip'); OperationFiles::copy($signature??$package.'.sig',$dir.'/package.zip.sig');
            $v=$this->verifyPackage($dir.'/package.zip');
            if (!$v['ok']||$v['mode']!=='dist'||version_compare($v['version'],$this->currentVersion(),'<=') ) throw new \RuntimeException($v['error']??'Expected newer dist');
            $data=['ok'=>true,'id'=>$id,'version'=>$v['version'],'sha256'=>$v['sha256'],'file_count'=>$v['file_count']];
            OperationFiles::json($this->requestDir().'/staged.json',$data,0644); return $data;
        } finally { OperationLock::release($lock); }
    }
    public function request(string $action, array $payload, string $actor): array
    {
        if (($payload['confirm']??null)!==true) throw new \RuntimeException('Explicit confirmation required');
        if (OperationLock::active($this->rootPath)||is_file($this->rootPath.'/storage/app/backup-restore.json')) throw new \RuntimeException('Recovery is required from CLI');
        $id=bin2hex(random_bytes(16)); $data=['ok'=>true,'id'=>$id,'action'=>$action,'actor'=>$actor,'requested_at'=>gmdate(DATE_ATOM),'state'=>'pending'];
        if ($action==='apply') {
            $stage=$this->staged();
            if (empty($stage['id'])||empty($stage['sha256'])||($stage['id']??null)!==($payload['package']??null)||($stage['sha256']??null)!==($payload['sha256']??null)) throw new \RuntimeException('Select the exact staged artifact and digest');
            $data['stage']=$stage['id']; $data['sha256']=$stage['sha256'];
        } elseif ($action==='backup') { /* full backup, no arbitrary server path */ } elseif ($action==='rollback') { $last=$this->wizardState()['last_report']; if (($last['state']??null)!=='committed') throw new \RuntimeException('No committed update'); $data['transaction']=$last['id']; }
        else throw new \RuntimeException('Invalid operation');
        OperationFiles::json($this->requestDir().'/request-'.$id.'.json',$data,0640); return $data;
    }
    public function pending(): array
    {
        $out=[]; foreach (glob($this->requestDir().'/request-*.json')?:[] as $file) { $data=OperationFiles::read($file); if (($data['state']??null)==='pending') $out[]=$data; } return $out;
    }
    public function runRequest(string $id): array
    {
        $this->cliOnly(); if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('Invalid request ID');
        // A request may be consumed only once, even by concurrent operators.
        $guardDir=$this->path('request-locks'); OperationFiles::dir($guardDir);
        $guard=OperationLock::childGuard($guardDir.'/'.$id.'.lock');
        try {
        $path=$this->requestDir().'/request-'.$id.'.json'; $r=OperationFiles::read($path);
        if (($r['id']??null)!==$id||($r['state']??null)!=='pending') throw new \RuntimeException('Request is not pending');
        // Operator explicitly selects ID, not a privileged daemon consuming arbitrary web requests.
        if ($r['action']==='apply') {
            if (!preg_match('/^[a-f0-9]{32}$/D',(string)($r['stage']??''))) throw new \RuntimeException('Invalid staged ID');
            $result=$this->apply(['package'=>$this->path('inbox/'.$r['stage'].'/package.zip'),'sha256'=>$r['sha256']]);
        } elseif ($r['action']==='rollback') {
            if (($this->wizardState()['last_report']['id']??null)!==($r['transaction']??null)) throw new \RuntimeException('Stale rollback request');
            $result=$this->rollback(null,true,$r['transaction']);
        } elseif ($r['action']==='backup') $result=(new BackupManager($this->rootPath,$this->config))->create();
        else throw new \RuntimeException('Unknown request');
        $r['state']=$result['ok']?'completed':'failed'; OperationFiles::json($path,$r,0640); return $result;
        } finally { OperationLock::release($guard); }
    }
    private function stage(string $dir,array $manifest): array
    {
        $files=[]; $zip=$dir.'/package.zip';
        foreach (['tools/update-postcheck.php','tools/update-recovery.php','core/Update/UpdateManager.php'] as $required) if (!in_array($required,$manifest['files'],true)) throw new \RuntimeException('Target lacks recovery protocol');
        foreach ($manifest['files'] as $p) {
            if (!$this->managed($p)) continue;
            $dest=OperationFiles::path($dir,'stage/'.$p);
            OperationFiles::copy('phar://'.$zip.'/cajeerengine/'.$p,$dest);
            if (!hash_equals($manifest['checksums'][$p],OperationFiles::hash($dest))) throw new \RuntimeException('Staged checksum mismatch');
            OperationFiles::path($this->rootPath,$p); $files[]=$p;
        }
        $removed=$manifest['removed_files']??[];
        // Only previously shipped paths are automatically retired; local custom files are not inferred as obsolete.
        if (is_file($this->rootPath.'/release-manifest.json')) {
            $prior=OperationFiles::read($this->rootPath.'/release-manifest.json');
            if (($prior['format']??null)==='cajeerengine.release-manifest.v5'&&($prior['version']??null)===$this->currentVersion()&&($prior['mode']??null)==='dist') {
                foreach ($prior['files']??[] as $p) if (is_string($p)&&$this->managed($p)&&!in_array($p,$files,true)) $removed[]=$p;
            }
        }
        // Remove obsolete Composer files, which cannot remain loadable after dependency replacement.
        foreach (OperationFiles::files($this->rootPath,'vendor') as $p) if ($this->managed($p)&&!in_array($p,$files,true)) $removed[]=$p;
        foreach ($removed as $p) { if (!$this->managed($p)||in_array($p,$files,true)) throw new \RuntimeException('Invalid removal'); OperationFiles::path($this->rootPath,$p); }
        return ['files'=>$files,'removed_files'=>array_values(array_unique($removed))];
    }
    private function managed(string $p): bool { return ReleaseFilePolicy::allows($p,'dist')&&!in_array($p,ReleaseFilePolicy::KEEP,true); }
    private function clearCaches(): void
    {
        foreach (['storage/cache','bootstrap/cache'] as $dir) foreach (OperationFiles::files($this->rootPath,$dir) as $file) if (basename($file)!=='.gitkeep') OperationFiles::remove(OperationFiles::path($this->rootPath,$file));
    }
    private function postCheck(bool $migrate): void
    {
        $tool=$this->rootPath.'/tools/update-postcheck.php';
        if (!is_file($tool)) throw new \RuntimeException('Release lacks updater health check');
        DatabaseBackup::run([PHP_BINARY,$tool,$migrate?'migrate':'verify']);
    }
    private function recoveryLauncher(string $dir,string $backup): void
    {
        // Backup contains the old core and its dependencies. Recovery entrypoint remains outside replaced tree.
        $source=$this->rootPath.'/tools/update-recovery.php';
        OperationFiles::copy($source,$dir.'/recover.php');
        OperationFiles::json($dir.'/recovery.json',['root'=>$this->rootPath,'backup'=>$backup]);
    }
    private function journal(array $j): void { $j['updated_at']=gmdate(DATE_ATOM); OperationFiles::json($this->transactionDir($j['id']).'/journal.json',$j); OperationFiles::json($this->path('active.json'),$j); }
    private function finish(array $j,array $report): void { $this->report($report); if (!$j['maintenance_was_enabled']) OperationFiles::remove($this->rootPath.'/storage/app/maintenance.json'); elseif (is_array($j['maintenance_before']??null)) OperationFiles::json($this->rootPath.'/storage/app/maintenance.json',$j['maintenance_before'],0644); OperationFiles::remove($this->path('active.json')); }
    private function report(array $report): void { OperationFiles::json($this->path('last-update.json'),$report,0600); OperationFiles::json($this->requestDir().'/last-report.json',array_intersect_key($report,array_flip(['ok','id','state','previous_version','target_version','version'])),0644); }
    private function setMaintenance(string $message): void { OperationFiles::json($this->rootPath.'/storage/app/maintenance.json',['enabled'=>true,'message'=>$message,'since'=>gmdate(DATE_ATOM)],0644); }
    private function transactionDir(string $id): string { if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) throw new \RuntimeException('Invalid transaction ID'); return $this->path('transactions/'.$id); }
    private function path(string $p): string { return OperationFiles::path($this->rootPath,'storage/app/updates/'.$p); }
    private function requestDir(): string { $p=OperationFiles::path($this->rootPath,'storage/app/update-requests'); if (!is_dir($p)) { OperationFiles::dir($p,0770); chmod($p,02770); } return $p; }
    private function trustConfigured(): bool { try { $this->trustedKey(); return true; } catch (\Throwable) { return false; } }
    private function trustedKey(): string { $p=(string)getenv('UPDATE_TRUSTED_PUBLIC_KEY_FILE'); if ($p===''||!str_starts_with($p,'/')||!is_file($p)||is_link($p)||(fileperms($p)&0022)!==0||str_starts_with(realpath($p)?:$p,rtrim($this->rootPath,'/').'/')) throw new \RuntimeException('Provision UPDATE_TRUSTED_PUBLIC_KEY_FILE'); return $p; }
    private function option(array $o,string $k): ?string { $v=$o[$k]??null; return is_string($v)&&trim($v)!==''?trim($v):null; }
    private function resolvePackage(string $p): string { if (str_contains($p,'://')||str_contains($p,"\0")) throw new \RuntimeException('Use local ZIP'); return str_starts_with($p,'/')?$p:$this->rootPath.'/'.$p; }
    private function currentVersion(): string { return trim((string)file_get_contents($this->rootPath.'/VERSION')); }
    private function cliOnly(): void { if (PHP_SAPI!=='cli') throw new \RuntimeException('Use dedicated CLI updater'); }
}
