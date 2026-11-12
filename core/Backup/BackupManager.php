<?php

declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Release\ReleaseFilePolicy;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;
use CajeerEngine\Update\OperationLock;

/** Local recovery sets: database + exact file inventory + encrypted environment. */
final class BackupManager
{
    private DatabaseBackup $db;
    private string $base;
    private const DATA_ROOTS=['storage/app','storage/queue','public/uploads','public/extensions','modules','plugins','themes'];
    private const OMIT=['storage/app/backups','storage/app/updates','storage/app/update-requests','storage/app/support','storage/app/reports','storage/app/operations.lock','storage/app/maintenance.json','storage/app/backup-restore.json','storage/app/import-staging','storage/app/import-requests','storage/app/import-reports'];
    public function __construct(private readonly string $root, private readonly ConfigRepository $config)
    {
        $this->db=new DatabaseBackup($root,$config);
        $this->base=F::path($root,'storage/app/backups/production');
    }
    public function create(): array
    {
        $lock=OperationLock::acquire($this->root,true);
        try { if (OperationLock::active($this->root)||is_file($this->root.'/storage/app/backup-restore.json')) throw new \RuntimeException('Recover the active operation first'); return $this->capture(); }
        finally { OperationLock::release($lock); }
    }
    /** Caller holds exclusive operation lock. */
    public function capture(): array
    {
        $this->assertStorage();
        $key=$this->secretKey(); sodium_memzero($key);
        $this->privateBase();
        $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        $dir=$this->base.'/'.$id; F::dir($dir); chmod($dir,0700);
        $manifest=['format'=>'cajeerengine.backup.v2','id'=>$id,'version'=>$this->version(),'created_at'=>gmdate(DATE_ATOM),'database'=>$this->db->identity(),'database_permissions'=>$this->db->permissions(),'files'=>[],'dirs'=>[],'env'=>null,'env_permissions'=>null];
        try {
            $this->db->create($dir.'/database.dump');
            $manifest['database_sha256']=F::hash($dir.'/database.dump');
            foreach (array_unique(array_merge($this->dataFiles(),$this->codeFiles())) as $relative) {
                $from=F::path($this->root,$relative); $to=F::path($dir,'files/'.$relative);
                F::copy($from,$to);
                $manifest['files'][$relative]=['sha256'=>F::hash($to)]+$this->permissions($from);
                for ($parent=dirname($relative);$parent!=='.';$parent=dirname($parent)) if (!isset($manifest['dirs'][$parent])) $manifest['dirs'][$parent]=$this->permissions(F::path($this->root,$parent),true);
            }
            $env=F::path($this->root,'.env');
            if (is_file($env)) {
                $key=$this->secretKey();
                try { $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $cipher=$nonce.sodium_crypto_secretbox((string)file_get_contents($env),$nonce,$key); F::write($dir.'/environment.enc',$cipher); }
                finally { sodium_memzero($key); }
                $manifest['env']=F::hash($dir.'/environment.enc'); $manifest['env_permissions']=$this->permissions($env);
            }
            ksort($manifest['files']); F::json($dir.'/manifest.json',$manifest);
            $key=$this->secretKey(); try { F::write($dir.'/manifest.mac',hash_hmac('sha256',(string)file_get_contents($dir.'/manifest.json'),$key)); } finally { sodium_memzero($key); }
            $this->verify($id);
            return ['ok'=>true,'id'=>$id,'target'=>$dir,'sha256'=>F::hash($dir.'/manifest.json'),'files'=>count($manifest['files']),'database'=>$manifest['database']['driver'],'version'=>$manifest['version']];
        } catch (\Throwable $e) { F::json($dir.'/incomplete.json',['error'=>'Backup failed','at'=>gmdate(DATE_ATOM)]); throw $e; }
    }
    public function list(): array
    {
        $items=[]; if (is_dir($this->base)) foreach (new \DirectoryIterator($this->base) as $entry) {
            if ($entry->isDot()||$entry->isLink()||!$entry->isDir()) continue;
            $id=$entry->getFilename(); if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) continue;
            try { $m=F::read($entry->getPathname().'/manifest.json'); $items[]=['id'=>$id,'version'=>$m['version'],'created_at'=>$m['created_at'],'complete'=>!is_file($entry->getPathname().'/incomplete.json')]; } catch (\Throwable) { $items[]=['id'=>$id,'complete'=>false]; }
        }
        usort($items,static fn($a,$b)=>strcmp($b['id'],$a['id'])); return ['ok'=>true,'backups'=>$items];
    }
    public function verify(string $id): array
    {
        $dir=$this->directory($id); F::hash($dir.'/manifest.json'); F::hash($dir.'/manifest.mac'); $key=$this->secretKey();
        try { $mac=hash_hmac('sha256',(string)file_get_contents($dir.'/manifest.json'),$key); if (!is_file($dir.'/manifest.mac')||!hash_equals($mac,trim((string)file_get_contents($dir.'/manifest.mac')))) throw new \RuntimeException('Backup manifest authentication failed'); } finally { sodium_memzero($key); }
        $m=F::read($dir.'/manifest.json');
        if (($m['format']??null)!=='cajeerengine.backup.v2'||($m['id']??null)!==$id||!is_array($m['files']??null)||is_file($dir.'/incomplete.json')) throw new \RuntimeException('Incomplete or invalid recovery set');
        if (($m['database']??null)!==$this->db->identity()||!hash_equals((string)($m['database_sha256']??''),F::hash($dir.'/database.dump'))) throw new \RuntimeException('Database backup identity/checksum mismatch');
        $this->db->verify($dir.'/database.dump');
        foreach ($m['files'] as $relative=>$info) {
            if (!$this->dataAllowed($relative)&&!$this->codeAllowed($relative)) throw new \RuntimeException('Forbidden backup path');
            if (!is_array($info)||!is_int($info['mode']??null)||!hash_equals((string)($info['sha256']??''),F::hash(F::path($dir,'files/'.$relative)))) throw new \RuntimeException('Backup file checksum mismatch: '.$relative);
        }
        foreach ($m['dirs']??[] as $relative=>$info) { F::path($this->root,$relative); if (!array_filter(array_keys($m['files']),static fn($p)=>str_starts_with($p,$relative.'/'))) throw new \RuntimeException('Invalid backup directory'); }
        $actual=F::files($dir,'files'); $expected=array_map(static fn($p)=>'files/'.$p,array_keys($m['files'])); sort($expected);
        if ($actual!==$expected) throw new \RuntimeException('Unexpected backup files');
        if (($m['env']??null)!==null) { if (!hash_equals((string)$m['env'],F::hash($dir.'/environment.enc'))) throw new \RuntimeException('Encrypted environment checksum mismatch'); $this->decryptEnv($dir); }
        return ['ok'=>true,'id'=>$id,'version'=>$m['version'],'files'=>count($m['files']),'manifest_sha256'=>F::hash($dir.'/manifest.json')];
    }
    public function restore(string $id): array
    {
        $lock=OperationLock::acquire($this->root,true); $child=null;
        try {
            if (OperationLock::active($this->root)) throw new \RuntimeException('Use update:rollback for active recovery');
            $this->verify($id);
            $marker=F::path($this->root,'storage/app/backup-restore.json');
            if (is_file($marker)) {
                $old=F::read($marker); if (($old['id']??null)!==$id) throw new \RuntimeException('Resume the interrupted restore with its original backup ID');
                if (!preg_match('/^restore-[a-f0-9]{16}\.lock$/D',(string)($old['child_lock']??''))) throw new \RuntimeException('Invalid restore lease');
                $child=OperationLock::childGuard($this->directory($id).'/'.$old['child_lock']);
            }
            $lease='restore-'.bin2hex(random_bytes(8)).'.lock';
            F::json($marker,['id'=>$id,'state'=>'restoring','child_lock'=>$lease,'at'=>gmdate(DATE_ATOM)],0640);
            DatabaseBackup::useProcessLock($this->directory($id).'/'.$lease);
            $maintenance=F::path($this->root,'storage/app/maintenance.json');
            F::json($maintenance,['enabled'=>true,'message'=>'Backup restore in progress','since'=>gmdate(DATE_ATOM)],0644);
            // Keep maintenance enabled on success too: operator must validate before reopening.
            $result=$this->restoreCaptured($id);
            DatabaseBackup::run([PHP_BINARY,$this->root.'/tools/update-postcheck.php','verify']);
            F::remove($marker);
            return $result;
        } finally { DatabaseBackup::useProcessLock(null); OperationLock::release($child); OperationLock::release($lock); }
    }
    /** Caller holds exclusive lock. Verify entire set before deleting or replacing anything. */
    public function restoreCaptured(string $id): array
    {
        $this->assertStorage(); $this->verify($id); $dir=$this->directory($id); $m=F::read($dir.'/manifest.json');
        $env=$m['env']!==null?$this->decryptEnv($dir):null;
        $current=array_unique(array_merge($this->dataFiles(),$this->codeFiles()));
        foreach ($m['files'] as $relative=>$info) F::path($this->root,$relative); // preflight symlinks
        $this->db->restore($dir.'/database.dump',$m['database'],$m['database_permissions']??[]);
        $dirs=$m['dirs']??[]; uksort($dirs,static fn($a,$b)=>substr_count($a,'/')<=>substr_count($b,'/'));
        foreach ($dirs as $relative=>$info) { $dest=F::path($this->root,$relative); F::dir($dest,$info['mode']); F::permissions($dest,$info['mode'],$info['uid'],$info['gid']); }
        foreach ($current as $relative) if (!array_key_exists($relative,$m['files'])) F::remove(F::path($this->root,$relative));
        foreach ($m['files'] as $relative=>$info) F::copy(F::path($dir,'files/'.$relative),F::path($this->root,$relative),$info['mode']&0777,$info['uid'],$info['gid']);
        if ($env!==null) F::write(F::path($this->root,'.env'),$env,$m['env_permissions']['mode'],$m['env_permissions']['uid'],$m['env_permissions']['gid']);
        else F::remove(F::path($this->root,'.env'));
        foreach ($m['files'] as $relative=>$info) if (!hash_equals($info['sha256'],F::hash(F::path($this->root,$relative)))) throw new \RuntimeException('Restored file verification failed');
        return ['ok'=>true,'id'=>$id,'version'=>$m['version'],'restored_files'=>array_keys($m['files']),'maintenance'=>true];
    }
    public function directory(string $id): string
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{16}$/D',$id)) throw new \RuntimeException('Invalid backup ID');
        return F::path($this->base,$id);
    }
    private function privateBase(): void { F::dir($this->base); if (!chmod($this->base,0700)) throw new \RuntimeException('Cannot protect backup directory'); }
    private function assertStorage(): void
    {
        if ($this->config->string('storage.default','local')!=='local'||!in_array($this->config->string('storage.disks.local.root','storage/app'),['storage/app',$this->root.'/storage/app'],true)) throw new \RuntimeException('External storage requires an independent consistent snapshot; automatic recovery supports default local storage only');
        if (!in_array($this->config->string('queue.default','file'),['file','pgsql','postgres','postgresql','database'],true)) throw new \RuntimeException('Drain/migrate external queues to a supported local or PostgreSQL queue before automatic recovery');
    }
    private function dataAllowed(string $p): bool
    {
        foreach (self::OMIT as $omit) if ($p===$omit||str_starts_with($p,$omit.'/')) return false;
        if ($this->db->identity()['driver']==='sqlite') {
            $db=$this->db->identity()['database'];
            foreach (['','-wal','-shm','-journal'] as $suffix) if ($this->root.'/'.$p===$db.$suffix) return false;
        }
        foreach (self::DATA_ROOTS as $root) if ($p===$root||str_starts_with($p,$root.'/')) return true;
        return false;
    }
    private function dataFiles(): array { $out=[]; foreach (self::DATA_ROOTS as $r) $out=array_merge($out,F::files($this->root,$r,fn($p)=>$this->dataAllowed($p))); return $out; }
    private function codeAllowed(string $p): bool { return $p==='release-manifest.json'||(ReleaseFilePolicy::allows($p,'dist')&&!$this->dataAllowed($p)&&!in_array($p,ReleaseFilePolicy::KEEP,true)); }
    private function codeFiles(): array
    {
        $out=[]; foreach (new \DirectoryIterator($this->root) as $entry) {
            if ($entry->isDot()) continue; $p=$entry->getFilename();
            if ($entry->isDir() && !ReleaseFilePolicy::traverses($p,'dist')) continue;
            if (!$entry->isDir()&&!$this->codeAllowed($p)) continue;
            $out=array_merge($out,F::files($this->root,$p,fn($path)=>$this->codeAllowed($path)||ReleaseFilePolicy::traverses($path,'dist')));
        }
        return array_values(array_filter($out,fn($p)=>$this->codeAllowed($p)));
    }
    private function secretKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new \RuntimeException('ext-sodium required');
        $path=(string)getenv('BACKUP_KEY_FILE');
        if ($path===''||!str_starts_with($path,'/')||!is_file($path)||is_link($path)||(fileperms($path)&0077)!==0) throw new \RuntimeException('Provision BACKUP_KEY_FILE outside web root with mode 0600');
        if (str_starts_with(realpath($path)?:$path,rtrim($this->root,'/').'/')) throw new \RuntimeException('Backup key must be outside the project');
        $key=base64_decode(trim((string)file_get_contents($path)),true);
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('Invalid backup encryption key');
        return $key;
    }
    private function decryptEnv(string $dir): string
    {
        $key=$this->secretKey();
        try { $cipher=(string)file_get_contents($dir.'/environment.enc'); $plain=sodium_crypto_secretbox_open(substr($cipher,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($cipher,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key); if ($plain===false) throw new \RuntimeException('Cannot decrypt environment backup'); return $plain; }
        finally { sodium_memzero($key); }
    }
    private function permissions(string $p,bool $dir=false): array { return ['mode'=>fileperms($p)&($dir?02777:0777),'uid'=>fileowner($p),'gid'=>filegroup($p)]; }
    private function version(): string { return trim((string)file_get_contents($this->root.'/VERSION')); }
}
