<?php
declare(strict_types=1);
namespace CajeerEngine\Extension;

use CajeerEngine\Backup\{BackupManager,DatabaseBackup};
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Extension\Runtime\ExtensionRuntime;
use CajeerEngine\Runtime\{ConfigRepository,ServiceContainer,EventDispatcher,RuntimeLogger};
use CajeerEngine\Update\{OperationFiles as F,OperationLock};

/** Operator-only install/update. Signed directory or ZIP; no PHP is executed before verification. */
final class PackageManager
{
    public function __construct(private string $root,private ConfigRepository $config) {}
    private function runtime(ExtensionRegistry $registry): ExtensionRuntime {
        if (class_exists(\Symfony\Component\Routing\RouteCollection::class)) return \CajeerEngine\Kernel\Application::extensionRuntimeForOperations($this->root);
        $container=new ServiceContainer(); $logger=new RuntimeLogger($this->root); $events=new EventDispatcher($logger);
        $container->instance(ConfigRepository::class,$this->config); $container->instance(DatabaseManager::class,new DatabaseManager($this->config));
        $db=$container->get(DatabaseManager::class);
        $container->instance(\CajeerEngine\Content\ContentTypeRepository::class,new \CajeerEngine\Content\ContentTypeRepository($this->root,$db));
        $container->instance(\CajeerEngine\Content\ContentEntryRepository::class,new \CajeerEngine\Content\ContentEntryRepository($this->root,$db));
        $container->instance(\CajeerEngine\Queue\ManagedQueueDriverInterface::class,(new \CajeerEngine\Queue\QueueFactory($this->root,$this->config,$db))->make());
        return new ExtensionRuntime($this->root,$registry,$container,$this->config,$events,$logger,$db);
    }
    public function deploy(string $package,bool $enable=false,bool $upgrade=false): array
    {
        $lease=OperationLock::acquire($this->root,true); $stage=$this->root.'/storage/tmp/extension-'.bin2hex(random_bytes(12)); $backup=null; $marker=$this->root.'/storage/app/maintenance.json'; $journal=$this->root.'/storage/app/extensions/deployment.json';
        try {
            if (OperationLock::maintenance($this->root)||is_file($journal)) throw new \RuntimeException('Maintenance/recovery active');
            F::dir($stage); $this->stage($package,$stage);
            $manifest=(new ManifestLoader())->load($stage.'/'.ManifestLoader::FILENAME);
            $trust=PackageTrust::verify($stage,$manifest,$this->config->string('extensions.trust_dir'));
            (new CompatibilityChecker($this->config->string('app.engine_api_version')))->assertCompatible($manifest);
            $registry=new ExtensionRegistry($this->root,$this->config); $old=$registry->findInstalled($manifest->name); $installed=!empty($old['installed']);
            if ($upgrade!==$installed) throw new \RuntimeException($upgrade?'Extension not installed':'Already installed; use upgrade');
            if ($upgrade && ($old['type']!==$manifest->type||!version_compare($manifest->version,$old['version'],'>'))) throw new \RuntimeException('Upgrade requires newer version of same type');
            if ($upgrade && array_diff($manifest->permissions,$old['permissions'])) throw new \RuntimeException('Upgrade adds permissions; review and reinstall explicitly');
            $base=$registry->paths()[$manifest->type]; F::dir($base,0755); $dest=$installed?$this->root.'/'.$old['path']:$base.'/'.$manifest->slug();
            if (dirname($dest)!==$base||is_link($dest)||(!$installed && file_exists($dest) && (!is_dir($dest)||count(scandir($dest))>2))) throw new \RuntimeException('Extension destination occupied or unsafe');
            // Check new graph without changing live files or registry.
            $items=array_values(array_filter($registry->all(),fn($x)=>$x['name']!==$manifest->name));
            $items[]=$manifest->toArray()+['installed'=>true,'enabled'=>$enable||!empty($old['enabled']),'valid'=>true]; DependencyGraph::order($items);
            (new DatabaseBackup($this->root,$this->config))->assertRecoveryReady(); $backupManager=new BackupManager($this->root,$this->config); $backup=$backupManager->capture();
            $this->beginJournal($journal,$backup['id'],$manifest->name,'applying');
            F::json($marker,['enabled'=>true,'message'=>'Extension deployment','since'=>gmdate(DATE_ATOM)],0644);
            if (is_dir($dest)) self::removeTree($dest); F::dir($dest,0755);
            foreach (PackageTrust::inventory($stage) as $path=>$sha) F::copy(F::path($stage,$path),F::path($dest,$path),0644);
            F::copy($stage.'/'.PackageTrust::FILE,$dest.'/'.PackageTrust::FILE,0644);
            $runtime=$this->runtime($registry);
            if ($upgrade) {
                $registry->install($manifest->name,$enable||!empty($old['enabled']));
                $runtime->migrate($manifest->name); $runtime->publishAssets($manifest->name);
                $runtime->upgradeLifecycle($manifest->name,$old['version']);
            } else $runtime->install($manifest->name,$enable,true,true,true);
            $runtime->boot();
            F::remove($journal); F::remove($marker);
            return ['ok'=>true,'name'=>$manifest->name,'version'=>$manifest->version,'backup'=>$backup['id'],'signature'=>$trust['status']];
        } catch (\Throwable $e) {
            if ($backup!==null && is_file($journal)) {
                try { (new BackupManager($this->root,$this->config))->restoreCaptured($backup['id']); F::remove($journal); F::remove($marker); }
                catch (\Throwable $recovery) { throw new \RuntimeException('Extension deployment failed; maintenance retained; restore backup '.$backup['id'].': '.$recovery->getMessage(),0,$e); }
            }
            throw $e;
        } finally { try { if (is_dir($stage)) self::removeTree($stage); } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($lease); } }
    }
    public function uninstall(string $name,bool $lifecycle=false): array
    {
        $lock=OperationLock::acquire($this->root,true); $backup=null; $marker=$this->root.'/storage/app/maintenance.json'; $journal=$this->root.'/storage/app/extensions/deployment.json';
        try {
            if (OperationLock::maintenance($this->root)) throw new \RuntimeException('Maintenance/recovery active');
            $registry=new ExtensionRegistry($this->root,$this->config); $item=$registry->findInstalled($name);
            if (empty($item['installed'])) throw new \RuntimeException('Extension not installed');
            $items=array_values(array_filter($registry->all(),fn($x)=>$x['name']!==$item['name'])); DependencyGraph::order($items);
            (new DatabaseBackup($this->root,$this->config))->assertRecoveryReady(); $manager=new BackupManager($this->root,$this->config); $backup=$manager->capture();
            $this->beginJournal($journal,$backup['id'],$item['name'],'uninstalling'); F::json($marker,['enabled'=>true,'message'=>'Extension removal'],0644);
            $result=$this->runtime($registry)->uninstall($item['name'],$lifecycle,true);
            $path=F::path($this->root,$item['path']); if(dirname($path)!==($registry->paths()[$item['type']]??null)) throw new \RuntimeException('Unsafe uninstall path'); if(is_dir($path)) self::removeTree($path);
            F::remove($journal); F::remove($marker); return $result+['backup'=>$backup['id'],'data_retained'=>!$lifecycle];
        } catch (\Throwable $e) { if($backup!==null&&is_file($journal)) { (new BackupManager($this->root,$this->config))->restoreCaptured($backup['id']); F::remove($journal); F::remove($marker); } throw $e; }
        finally { DatabaseBackup::useProcessLock(null); OperationLock::release($lock); }
    }
    public function activate(string $name): array
    {
        $lock=OperationLock::acquire($this->root,true); $backup=null; $marker=$this->root.'/storage/app/maintenance.json'; $journal=$this->root.'/storage/app/extensions/deployment.json';
        try {
            if (OperationLock::maintenance($this->root)) throw new \RuntimeException('Maintenance/recovery active');
            $registry=new ExtensionRegistry($this->root,$this->config); $item=$registry->findInstalled($name);
            if (empty($item['installed'])||empty($item['valid'])) throw new \RuntimeException('Extension not installed or untrusted');
            if (!empty($item['enabled'])) return ['ok'=>true,'name'=>$item['name'],'already_enabled'=>true];
            (new DatabaseBackup($this->root,$this->config))->assertRecoveryReady(); $backup=(new BackupManager($this->root,$this->config))->capture(); $this->beginJournal($journal,$backup['id'],$item['name'],'activating'); F::json($marker,['enabled'=>true,'message'=>'Extension activation'],0644);
            $registry->enable($item['name']); $this->runtime($registry)->boot(); F::remove($journal); F::remove($marker); return ['ok'=>true,'name'=>$item['name'],'backup'=>$backup['id']];
        } catch (\Throwable $e) { if ($backup!==null&&is_file($journal)) { (new BackupManager($this->root,$this->config))->restoreCaptured($backup['id']); F::remove($journal); F::remove($marker); } throw $e; }
        finally { DatabaseBackup::useProcessLock(null); OperationLock::release($lock); }
    }
    private function beginJournal(string $path,string $backup,string $name,string $state): void
    {
        $child='extension-child-'.bin2hex(random_bytes(8)).'.lock';
        F::json($path,['backup'=>$backup,'name'=>$name,'state'=>$state,'child_lock'=>$child],0640);
        DatabaseBackup::useProcessLock((new BackupManager($this->root,$this->config))->directory($backup).'/'.$child);
    }
    public function recover(): array
    {
        $lock=OperationLock::acquire($this->root,true); $guard=null;
        try {
            $journal=$this->root.'/storage/app/extensions/deployment.json'; $record=F::read($journal); $manager=new BackupManager($this->root,$this->config); $dir=$manager->directory($record['backup']);
            if (!preg_match('/^extension-child-[a-f0-9]{16}\.lock$/D',(string)($record['child_lock']??''))) throw new \RuntimeException('Invalid extension recovery lease');
            $guard=OperationLock::childGuard($dir.'/'.$record['child_lock']);
            $this->beginJournal($journal,$record['backup'],$record['name'],'recovering');
            F::json($this->root.'/storage/app/maintenance.json',['enabled'=>true,'message'=>'Extension recovery'],0644);
            $result=$manager->restoreCaptured($record['backup']); F::remove($journal); F::remove($this->root.'/storage/app/maintenance.json'); return $result;
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($guard); OperationLock::release($lock); }
    }
    private function stage(string $package,string $stage): void
    {
        $source=realpath($package); if ($source===false||is_link($package)) throw new \RuntimeException('Package not found');
        if (is_dir($source)) { foreach (PackageTrust::inventory($source) as $p=>$sha) F::copy(F::path($source,$p),F::path($stage,$p),0644); F::copy($source.'/'.PackageTrust::FILE,$stage.'/'.PackageTrust::FILE,0644); return; }
        if (!str_ends_with(strtolower($source),'.zip')||filesize($source)>128*1024*1024) throw new \RuntimeException('Expected bounded ZIP package');
        $zip=new \PharData($source); $prefix='phar://'.$source.'/'; $count=0;$size=0;
        foreach (new \RecursiveIteratorIterator($zip,\RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $path=substr($entry->getPathname(),strlen($prefix));
            if (!preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D',$path)||$entry->isLink()) throw new \RuntimeException('Unsafe ZIP entry');
            if ($entry->isDir()) continue;
            $size+=$entry->getSize(); if (++$count>4097||$size>128*1024*1024) throw new \RuntimeException('ZIP limit exceeded');
            $target=F::path($stage,$path); if (file_exists($target)) throw new \RuntimeException('Duplicate ZIP entry');
            F::dir(dirname($target),0755); $in=fopen($entry->getPathname(),'rb');$out=fopen($target,'xb');
            try { if (!$in||!$out||stream_copy_to_stream($in,$out,$entry->getSize()+1)!==$entry->getSize()) throw new \RuntimeException('ZIP extraction failed'); } finally { if (is_resource($in)) fclose($in); if (is_resource($out)) fclose($out); }
        }
    }
    private static function removeTree(string $dir): void { if (is_link($dir)) throw new \RuntimeException('Symlink directory'); foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f) { if ($f->isLink()) throw new \RuntimeException('Symlink in package'); if ($f->isDir()) { if(!rmdir($f->getPathname())) throw new \RuntimeException('Cannot remove directory'); } elseif(!unlink($f->getPathname())) throw new \RuntimeException('Cannot remove file'); } if(!rmdir($dir)) throw new \RuntimeException('Cannot remove package'); }
}
