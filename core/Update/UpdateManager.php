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
        $package=$this->option($options,'package');
        // A local, signed dist must remain installable during a GitHub/GitFlic outage.
        // Resolving remote catalogs here used to make update:prepare fail offline.
        $check=$package===null?$this->check():['latest'=>null,'update_available'=>false];
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
            if (!is_string($version)||!preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/D',$version)||!in_array($mode,['source','dist'],true)) throw new \RuntimeException('Invalid release identity');
            $m=(new ReleaseArtifactVerifier())->verify($packagePath,$mode,$version);
            foreach ($m['removed_files']??[] as $file) if (!is_string($file)||!$this->managed($file)||in_array($file,$m['files'],true)) throw new \RuntimeException('Invalid removed file');
            if (!self::supportsRuntime(PHP_VERSION)) throw new \RuntimeException('Unsupported PHP runtime');
            return ['ok'=>true,'package'=>$packagePath,'version'=>$version,'mode'=>$mode,'manifest'=>$m,'file_count'=>count($m['files'])]+$sig;
        } catch (\Throwable $e) { return ['ok'=>false,'error'=>$e->getMessage()]; }
    }
    private static function supportsRuntime(string $version): bool
    {
        return version_compare($version, '8.4.1', '>=') && version_compare($version, '9.0', '<');
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
            $this->assertEnabledExtensionCompatibility($dir, (string) $v['version']);
            $report=['ok'=>true,'id'=>$id,'previous_version'=>$previous,'target_version'=>$v['version'],'sha256'=>$v['sha256'],'copy_plan'=>$plan,'dry_run'=>!empty($options['dry_run'])];
            if ($report['dry_run']) return $report;
            $backup=new BackupManager($this->rootPath,$this->config);
            $stageBytes = 0;
            foreach ($plan['files'] as $relative) {
                $size = filesize(OperationFiles::path($dir, 'stage/'.$relative));
                if ($size === false) throw new \RuntimeException('Cannot size staged release');
                $stageBytes += $size;
            }
            $backup->preflightUpdate($stageBytes);
            $this->postCheck(false);
            $journal=['id'=>$id,'state'=>'prepared','previous_version'=>$previous,'target_version'=>$v['version'],'sha256'=>$v['sha256'],'maintenance_was_enabled'=>$this->maintenanceState()['enabled'],'maintenance_before'=>is_file($this->rootPath.'/storage/app/maintenance.json')?OperationFiles::read($this->rootPath.'/storage/app/maintenance.json'):null,'backup'=>null,'child_lock'=>'child-'.bin2hex(random_bytes(8)).'.lock','at'=>gmdate(DATE_ATOM)];
            $this->journal($journal);
            DatabaseBackup::useProcessLock($dir.'/'.$journal['child_lock']);
            try {
                $this->setMaintenance('Updating to '.$v['version']);
                $journal['state']='backup'; $this->journal($journal);
                $journal['backup']=$backup->capture()['id'];
                $backup->verify($journal['backup']);
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
                \CajeerEngine\Observability\OperationsMonitor::marker($this->rootPath,'update');
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
                    $this->finish($journal,$result); \CajeerEngine\Observability\OperationsMonitor::marker($this->rootPath,'update','failure'); return $result;
                } catch (\Throwable $recovery) {
                    $journal['state']='recovery_required'; $journal['recovery_error']=$recovery->getMessage(); $this->journal($journal);
                    \CajeerEngine\Observability\OperationsMonitor::marker($this->rootPath,'update','failure');
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
            // Any committed or interrupted destructive operation requires an
            // authenticated recovery set and working DB recovery permissions.
            if (!in_array($j['state'], ['prepared','backup','backed_up'], true)) {
                if (!is_string($j['backup'] ?? null)) throw new \RuntimeException('Missing rollback snapshot');
                $recovery=(new BackupManager($this->rootPath,$this->config))->preflightRestore($j['backup']);
                if (($recovery['version'] ?? null) !== ($j['previous_version'] ?? null)) {
                    throw new \RuntimeException('Recovery snapshot version does not match update transaction');
                }
            }
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
        $operatorStatus=UpdateOperator::health($this->rootPath, $this->config);
        $rollback = ['available'=>false];
        if (($report['state'] ?? null) === 'committed' && !OperationLock::active($this->rootPath) &&
            ($report['target_version'] ?? null) === $this->currentVersion() &&
            preg_match('/^\\d{8}-\\d{6}-[a-f0-9]{16}$/D', (string)($report['id'] ?? ''))) {
            $rollback = ['available'=>true,'transaction'=>$report['id'],
                'from_version'=>$report['target_version'],'to_version'=>$report['previous_version'] ?? null];
        }
        $staged = $this->staged();
        $stagedInstallable = isset($staged['id'], $staged['sha256'], $staged['version']) &&
            is_string($staged['id']) && (bool) preg_match('/^[a-f0-9]{32}$/D', $staged['id']) &&
            is_string($staged['sha256']) && (bool) preg_match('/^[a-f0-9]{64}$/D', $staged['sha256']) &&
            is_string($staged['version']) && version_compare($staged['version'], $this->currentVersion(), '>') &&
            is_file($this->path('inbox/' . $staged['id'] . '/package.zip')) &&
            is_file($this->path('inbox/' . $staged['id'] . '/package.zip.sig'));
        return ['ok'=>true,'staged_installable'=>$stagedInstallable,'rollback'=>$rollback,'current_version'=>$this->currentVersion(),'maintenance'=>$this->maintenanceState(),'last_report'=>$report===null?null:array_intersect_key($report,array_flip(['ok','id','state','previous_version','target_version','version'])),'installed'=>is_file($this->rootPath.'/storage/app/installed.lock'),'production_apply_enabled'=>$this->trustConfigured(),'operator_enabled'=>$this->config->bool('updates.operator.enabled'),'operator_ready'=>$operatorStatus['ready'],'operator_status'=>$operatorStatus];
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
        // Concurrent browser approvals must be serialized independently of the
        // activity lease, which may already be held shared by HTTP middleware.
        $queueLock = $this->requestDir() . '/queue.lock';
        $queueGuard = OperationLock::openLockFile($queueLock, 0660);
        if (!is_resource($queueGuard) || !flock($queueGuard, LOCK_EX)) {
            if (is_resource($queueGuard)) fclose($queueGuard);
            throw new \RuntimeException('Could not lock update request queue');
        }
        try {
        foreach (new \DirectoryIterator($this->requestDir()) as $entry) {
            if ($entry->isDot() || !str_starts_with($entry->getFilename(), 'request-')) continue;
            if (!preg_match('/^request-[a-f0-9]{32}\.json$/D', $entry->getFilename()) || $entry->isLink() || !$entry->isFile()) {
                throw new \RuntimeException('Unsafe update request journal');
            }
            $existing = OperationFiles::read($entry->getPathname());
            if (($existing['id'] ?? '') !== substr($entry->getFilename(), 8, 32)) throw new \RuntimeException('Request journal ID mismatch');
            if (in_array($existing['state'] ?? null, ['pending','running'], true)) {
                throw new \RuntimeException('Another update operation is pending or running');
            }
        }
        $id=bin2hex(random_bytes(16)); $data=['ok'=>true,'id'=>$id,'action'=>$action,'actor'=>$actor,'requested_at'=>gmdate(DATE_ATOM),'confirmed_at'=>gmdate(DATE_ATOM),'state'=>'pending'];
        if ($action==='download') {
            $version = $payload['version'] ?? null;
            if (!is_string($version) || strlen($version) > 100 || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $version)) {
                throw new \RuntimeException('Select a valid GitHub Release version');
            }
            // Queue submission never contacts GitHub from PHP-FPM: the administrator
            // must first press Check, which persists the resolver snapshot. The
            // operator downloads ONLY after a fresh independent GitHub check.
            $check = (new UpdateResolver($this->config->get('updates', []), $this->rootPath))->diagnostics($this->currentVersion())['last_check'] ?? null;
            if (!is_array($check) || !($check['update_available'] ?? false) || ($check['latest']['version'] ?? null) !== $version ||
                !in_array($check['metadata_status'] ?? '', ['fresh', 'not_modified'], true) ||
                ($check['metadata_source'] ?? '') !== GitHubEndpoints::RELEASES_URL ||
                !is_string($check['checked_at'] ?? null) ||
                ($checked = strtotime($check['checked_at'])) === false || $checked > time() + 60 || $checked < time() - 3600) {
                throw new \RuntimeException('Check GitHub Releases first; a fresh eligible release must be selected');
            }
            $data['version'] = $version;
        } elseif ($action==='apply') {
            if (!$this->wizardState()['staged_installable']) throw new \RuntimeException('No installable staged release; re-download and verify the update');
            $stage=$this->staged();
            if (empty($stage['id'])||empty($stage['sha256'])||$stage['id']!==($payload['package']??null)||$stage['sha256']!==($payload['sha256']??null)) throw new \RuntimeException('Select the exact staged artifact and digest');
            $data['stage']=$stage['id']; $data['sha256']=$stage['sha256'];
        } elseif ($action==='backup') { /* full backup, no arbitrary server path */ } elseif ($action==='rollback') {
            $candidate=$this->wizardState()['rollback'];
            if (!$candidate['available'] || ($payload['transaction'] ?? null) !== $candidate['transaction']) {
                throw new \RuntimeException('Select the exact latest committed update to roll back');
            }
            $data['transaction']=$candidate['transaction'];
        }
        else throw new \RuntimeException('Invalid operation');
        $requestFile=$this->requestDir().'/request-'.$id.'.json';
        OperationFiles::json($requestFile,$data,0640);
        try {
            (new UpdateHistory($this->rootPath))->record('update.requested', [
                'actor'=>$actor, 'action'=>$action, 'request_id'=>$id, 'version'=>$data['version']??null,
                'sha256'=>$data['sha256']??null,
            ], 'request:'.$id.':pending');
        } catch (\Throwable $e) {
            // Do not leave a runnable unlogged request when durable audit storage fails.
            OperationFiles::remove($requestFile);
            throw $e;
        }
        return $data;
        } finally { flock($queueGuard, LOCK_UN); fclose($queueGuard); }
    }
    public function pending(): array
    {
        $out=[]; foreach (glob($this->requestDir().'/request-*.json')?:[] as $file) { $data=OperationFiles::read($file); if (($data['state']??null)==='pending') $out[]=$data; } return $out;
    }
    /** Browser-safe, bounded status view; never return server paths or package bytes. */
    public function recentRequests(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $items = [];
        $dir = $this->requestDir();
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if (!str_starts_with($name, 'request-')) continue;
            if (!preg_match('/^request-[a-f0-9]{32}\.json$/D', $name) || $entry->isLink() || !$entry->isFile()) {
                throw new \RuntimeException('Unsafe update request journal');
            }
            $record = OperationFiles::read($entry->getPathname());
            if (($record['id'] ?? '') !== substr($name, 8, 32)) throw new \RuntimeException('Request journal ID mismatch');
            $items[] = array_intersect_key($record, array_flip([
                'id', 'action', 'actor', 'state', 'version', 'requested_at', 'confirmed_at', 'started_at', 'finished_at', 'failure', 'sha256',
            ]));
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? '')) ?: strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? '')));
        return array_slice($items, 0, $limit);
    }

    /** Read the signed download receipt for UI; staging will independently verify it. */
    public function downloaded(): array
    {
        $dir = $this->path('downloads');
        if (!is_dir($dir) || is_link($dir)) return [];
        $results = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink() ||
                !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $entry->getFilename())) continue;
            $receiptPath = $entry->getPathname() . '/verified.json';
            if (!is_file($receiptPath) || is_link($receiptPath)) continue;
            $receipt = OperationFiles::read($receiptPath);
            if (($receipt['format'] ?? '') !== 'cajeerengine.verified-release.v1' || ($receipt['version'] ?? null) !== $entry->getFilename() ||
                !preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['sha256'] ?? ''))) continue;
            $results[] = array_intersect_key($receipt, array_flip(['version', 'channel', 'sha256', 'size', 'downloaded_at', 'key_id']));
        }
        usort($results, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
        return array_slice($results, 0, 20);
    }
    public function runRequest(string $id): array
    {
        $this->cliOnly(); if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('Invalid request ID');
        // A request may be consumed only once, even by concurrent operators.
        $guardDir=$this->path('request-locks'); OperationFiles::dir($guardDir);
        $guard=OperationLock::childGuard($guardDir.'/'.$id.'.lock');
        try {
        $path=$this->requestDir().'/request-'.$id.'.json'; $r=OperationFiles::read($path);
        if (($r['id']??null)!==$id||($r['state']??null)!=='pending') throw new \RuntimeException('Request is not pending; inspect update:status and recover any active operation');
        UpdateOperator::assertApproved($r, $id);
        // The durable state transition precedes every side effect. An interrupted
        // operator request is never replayed blindly: reconcile its update journal
        // or backup set before creating a new request.
        if (!in_array($r['action']??null,['apply','download','rollback','backup'],true)) throw new \RuntimeException('Unknown request');
        $r['state']='running'; $r['started_at']=gmdate(DATE_ATOM);
        OperationFiles::json($path,$r,0640);
        // The durable request journal is the authoritative recovery source.
        // The scheduler reconciles any audit event interrupted after this write.
        try { (new UpdateHistory($this->rootPath))->record('update.started',
            ['actor'=>$r['actor']??null,'action'=>$r['action'],'request_id'=>$id,'version'=>$r['version']??null],
            'request:'.$id.':started'); } catch (\Throwable) { /* reconcile later */ }
        try {
        // Only a manually selected ID or the dedicated non-root 2.0.3 operator
        // may consume this confirmed request; neither accepts browser-selected commands.
        if ($r['action']==='download') {
            if (!is_string($r['version'] ?? null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $r['version'])) {
                throw new \RuntimeException('Invalid approved GitHub Release version');
            }
            $download = (new GitHubReleaseDownloader($this->rootPath, $this->config))->download($r['version']);
            // Browser approval always stages the exact verified ZIP and signature;
            // the download path is never read from request payloads.
            $result = $this->publish($download['package']);
            if (!hash_equals((string) $download['sha256'], (string) ($result['sha256'] ?? '')) ||
                ($result['version'] ?? null) !== $r['version']) {
                throw new \RuntimeException('Published package does not match approved release');
            }
        } elseif ($r['action']==='apply') {
            if (!preg_match('/^[a-f0-9]{32}$/D',(string)($r['stage']??''))) throw new \RuntimeException('Invalid staged ID');
            $result=$this->apply(['package'=>$this->path('inbox/'.$r['stage'].'/package.zip'),'sha256'=>$r['sha256']]);
        } elseif ($r['action']==='rollback') {
            if (($this->wizardState()['last_report']['id']??null)!==($r['transaction']??null)) throw new \RuntimeException('Stale rollback request');
            $result=$this->rollback(null,true,$r['transaction']);
        } elseif ($r['action']==='backup') $result=(new BackupManager($this->rootPath,$this->config))->create();
        else throw new \RuntimeException('Unknown request');
        $r['state']=($result['ok']??false)?'completed':'failed'; $r['finished_at']=gmdate(DATE_ATOM);
        OperationFiles::json($path,$r,0640);
        try {
            $event = match ($r['action']) {
                'download' => 'update.downloaded', 'backup' => 'update.backup_created', default => null,
            };
            if ($event !== null) (new UpdateHistory($this->rootPath))->record($event,
                ['actor'=>$r['actor']??null,'action'=>$r['action'],'request_id'=>$id,
                 'version'=>$r['version']??($result['version']??null),
                 'transaction_id'=>$r['transaction']??null,
                 'backup_id'=>$result['id']??($result['backup']['id']??null),
                 'sha256'=>$r['sha256']??($result['sha256']??null)],
                'request:'.$id.':completed');
        } catch (\Throwable) { /* authoritative journal is reconciled by scheduler */ }
        return $result;
        } catch (\Throwable $e) {
            $r['state']='failed'; $r['finished_at']=gmdate(DATE_ATOM);
            $r['failure']='Operator operation failed; inspect the update/backup journal before retrying';
            OperationFiles::json($path,$r,0640);
            try { (new UpdateHistory($this->rootPath))->record('update.failed',
                ['actor'=>$r['actor']??null,'action'=>$r['action'],'request_id'=>$id,'version'=>$r['version']??null,
                 'reason_code'=>'operation_failed'], 'request:'.$id.':failed'); } catch (\Throwable) { /* reconcile */ }
            throw $e;
        }
        } finally { OperationLock::release($guard); }
    }
    /**
     * Refuse an incompatible Engine API upgrade before maintenance, backup or file replacement.
     * Only the authenticated, SHA-256-verified dist staged above supplies the target API.
     */
    private function assertEnabledExtensionCompatibility(string $transactionDir, string $targetVersion): void
    {
        $path = $transactionDir . '/stage/release.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Update is missing the target release metadata');
        }
        $release = OperationFiles::read($path);
        if (($release['version'] ?? null) !== $targetVersion ||
            !is_string($release['engine_api_version'] ?? null)) {
            throw new \RuntimeException('Invalid target Engine API metadata');
        }
        $registryFile = $this->rootPath . '/storage/app/extensions/registry.json';
        if (!is_file($registryFile)) {
            return;
        }
        $registry = OperationFiles::read($registryFile);
        if (!is_array($registry['extensions'] ?? null)) {
            throw new \RuntimeException('Invalid extension registry');
        }
        foreach ($registry['extensions'] as $name => $record) {
            if (!is_array($record) || empty($record['enabled'])) {
                continue;
            }
            $relative = $record['path'] ?? null;
            if (!is_string($relative) || $relative === '') {
                throw new \RuntimeException('Enabled extension has no package path: ' . $name);
            }
            $manifest = (new \CajeerEngine\Extension\ManifestLoader())->load(
                OperationFiles::path($this->rootPath, $relative . '/cajeer.extension.json'),
            );
            $constraint = $record['engine'] ?? null;
            if ($manifest->name !== $name || $manifest->version !== ($record['version'] ?? null) ||
                $manifest->engineConstraint !== $constraint) {
                throw new \RuntimeException('Installed extension manifest differs from registry: ' . $name);
            }
            if ($constraint === '') {
                throw new \RuntimeException('Enabled extension has no Engine API constraint: ' . $name);
            }
            try {
                $compatible = \CajeerEngine\Extension\VersionConstraint::matches($release['engine_api_version'], $constraint);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('Enabled extension has an invalid Engine API constraint: ' . $name, 0, $e);
            }
            if (!$compatible) {
                throw new \RuntimeException('Update blocks incompatible enabled extension: ' . $name . ' (' . $constraint . ')');
            }
        }
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
    private function finish(array $j,array $report): void
    {
        $this->report($report);
        if (!$j['maintenance_was_enabled']) OperationFiles::remove($this->rootPath.'/storage/app/maintenance.json');
        elseif (is_array($j['maintenance_before']??null)) OperationFiles::json($this->rootPath.'/storage/app/maintenance.json',$j['maintenance_before'],0644);
        OperationFiles::remove($this->path('active.json'));
        // Update reporting is non-destructive; a crash between the committed
        // journal and this event is backfilled from last-update.json.
        try {
            $event = match ($report['state']??'') {
                'committed' => 'update.installed', 'rolled_back' => ($report['ok']??false) ? 'update.rolled_back' : 'update.failed',
                'recovery_required' => 'update.recovery_required', default => null,
            };
            if ($event !== null) (new UpdateHistory($this->rootPath))->record($event,
                ['transaction_id'=>$j['id'], 'version'=>$j['target_version']??null,
                 'previous_version'=>$j['previous_version']??null, 'sha256'=>$j['sha256']??null,
                 'backup_id'=>$j['backup']??null, 'state'=>$report['state']??null],
                'transaction:'.$j['id'].':'.$report['state'].':'.(($report['ok']??false)?'ok':'failed'));
        } catch (\Throwable) { /* recovered by UpdateHistory::reconcile() */ }
    }
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
