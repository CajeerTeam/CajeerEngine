<?php

declare(strict_types=1);
namespace CajeerEngine\Backup;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Update\OperationFiles as F;

/** Dedicated database backup/restore. Commands are argv arrays, never shell strings. */
final class DatabaseBackup
{
    private static ?string $processLock = null;
    public static function useProcessLock(?string $path): void { self::$processLock=$path; }
    private string $driver;
    private array $connection;
    public function __construct(private readonly string $root, ConfigRepository $config)
    {
        $this->driver = $config->string('database.default','pgsql');
        if ($this->driver==='mariadb') $this->driver='mysql';
        if (!in_array($this->driver,['sqlite','pgsql','mysql'],true)) throw new \RuntimeException('Unsupported database backup driver');
        $this->connection=$config->get('database.connections.'.$this->driver,[]);
        if (!empty($this->connection['dsn'])) throw new \RuntimeException('Backup requires explicit connection configuration, not custom DSN');
    }
    public function identity(): array
    {
        return ['driver'=>$this->driver,'database'=>$this->driver==='sqlite'?$this->sqlitePath():$this->name(),'host'=>$this->driver==='sqlite'?null:(string)($this->connection['host']??'127.0.0.1'),'port'=>$this->driver==='sqlite'?null:(int)($this->connection['port']??0)];
    }
    public function assertRecoveryReady(): void
    {
        if ($this->driver==='pgsql') {
            $tmp=tempnam(sys_get_temp_dir(),'ce-db-check-'); if ($tmp===false) throw new \RuntimeException('Cannot create DB probe');
            try {
                self::run(['psql','--no-password','--tuples-only','--no-align','--dbname='.$this->name(),'--command=SELECT CASE WHEN (r.rolcreatedb OR r.rolsuper) AND (d.datdba=r.oid OR r.rolsuper) THEN \'ok\' ELSE \'denied\' END FROM pg_roles r JOIN pg_database d ON d.datname=current_database() WHERE r.rolname=current_user'],$this->pgEnv(),$tmp);
                if (trim((string)file_get_contents($tmp))!=='ok') throw new \RuntimeException('PostgreSQL recovery requires database owner with CREATEDB');
                self::run(['psql','--no-password','--dbname=postgres','--command=SELECT 1'],$this->pgEnv());
            } finally { F::remove($tmp); }
        } elseif ($this->driver==='mysql' && getenv('BACKUP_MYSQL_RESTORE_CONFIRMED')!=='true') {
            throw new \RuntimeException('Rehearse MySQL backup/restore on a disposable database, then explicitly set BACKUP_MYSQL_RESTORE_CONFIRMED=true');
        }
    }
    public function create(string $file): void
    {
        if ($this->driver==='sqlite') {
            $path=$this->sqlitePath();
            if (!is_file($path)||is_link($path)||file_exists($file)) throw new \RuntimeException('SQLite database missing or destination exists');
            if (in_array('sqlite',\PDO::getAvailableDrivers(),true)) {
                $pdo=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('PRAGMA busy_timeout=10000');
                $pdo->exec('VACUUM INTO '.$pdo->quote($file)); $pdo=null;
            } else $this->sqlitePython('backup',$path,$file);
        } elseif ($this->driver==='pgsql') {
            self::run(['pg_dump','--format=custom','--create','--no-password','--file='.$file,'--dbname='.$this->name()],$this->pgEnv());
        } else {
            $this->mysql(['mysqldump','--single-transaction','--quick','--routines','--events','--triggers','--hex-blob','--add-drop-database','--databases',$this->name()],$file,null);
        }
        if (!is_file($file)||filesize($file)===0||!chmod($file,0600)) throw new \RuntimeException('Database backup empty or unreadable');
        $h=fopen($file,'r+b'); if ($h===false) throw new \RuntimeException('Cannot sync database dump');
        try { if (!fsync($h)) throw new \RuntimeException('Cannot sync database dump'); } finally { fclose($h); }
        F::syncDir(dirname($file));
        $this->verify($file);
    }
    public function verify(string $file): void
    {
        if (!is_file($file)||is_link($file)||filesize($file)===0) throw new \RuntimeException('Missing database backup');
        if ($this->driver==='sqlite') {
            if (in_array('sqlite',\PDO::getAvailableDrivers(),true)) {
                $pdo=new \PDO('sqlite:'.$file,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                if ($pdo->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new \RuntimeException('SQLite integrity check failed');
            } else $this->sqlitePython('verify',$file);
        } elseif ($this->driver==='pgsql') self::run(['pg_restore','--list',$file],$this->pgEnv());
        else {
            // MySQL SQL dump is verified by digest; actual restore is separately tested in CI.
            $fp=fopen($file,'rb'); $head=fread($fp,8192); fclose($fp);
            if (!str_contains($head,'-- MySQL dump')&&!str_contains($head,'-- MariaDB dump')) throw new \RuntimeException('Invalid MySQL dump header');
        }
    }
    public function restore(string $file, array $identity, array $permissions=[]): void
    {
        if ($identity!==$this->identity()) throw new \RuntimeException('Backup DB identity differs from configured database');
        $this->verify($file);
        if ($this->driver==='sqlite') {
            $path=$this->sqlitePath();
            // Caller holds the exclusive lease and all external database clients must be stopped.
            foreach (['-wal','-shm','-journal'] as $suffix) F::remove($path.$suffix);
            F::copy($file,$path,(int)($permissions['mode']??0600),$permissions['uid']??null,$permissions['gid']??null);
        } elseif ($this->driver==='pgsql') {
            if ($this->name()==='postgres'||$this->name()==='template0'||$this->name()==='template1') throw new \RuntimeException('Refusing restore of PostgreSQL system DB');
            self::run(['pg_restore','--exit-on-error','--clean','--if-exists','--create','--no-password','--dbname=postgres',$file],$this->pgEnv());
        } else $this->mysql(['mysql','--binary-mode'],null,$file);
    }
    public function permissions(): array
    {
        if ($this->driver!=='sqlite') return [];
        $p=$this->sqlitePath(); if (!is_file($p)) throw new \RuntimeException('SQLite database missing');
        return ['mode'=>fileperms($p)&0777,'uid'=>fileowner($p),'gid'=>filegroup($p)];
    }
    private function sqlitePython(string $action,string $file,?string $target=null): void
    {
        // Optional independent recovery path when the PHP SQLite extension is unavailable.
        $python=(string)getenv('BACKUP_SQLITE_PYTHON');
        if ($python===''||!str_starts_with($python,'/')||!is_executable($python)) throw new \RuntimeException('Install pdo_sqlite or configure BACKUP_SQLITE_PYTHON=/absolute/path/to/python3');
        $script=<<<'PYTHON'
import pathlib, sqlite3, sys
mode, source = sys.argv[1:3]
with sqlite3.connect(pathlib.Path(source).resolve().as_uri() + '?mode=ro', uri=True, timeout=10) as db:
    if mode == 'backup':
        with sqlite3.connect(sys.argv[3], timeout=10) as out:
            db.backup(out)
    elif db.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
        raise RuntimeError('SQLite integrity check failed')
PYTHON;
        self::run([$python,'-I','-c',$script,$action,$file,$target??'']);
    }
    private function sqlitePath(): string
    {
        $path=(string)($this->connection['database']??'');
        if ($path===''||$path===':memory:') throw new \RuntimeException('Persistent SQLite database required');
        if (!str_starts_with($path,'/')) $path=F::path($this->root,$path);
        $parent=realpath(dirname($path));
        if ($parent===false||is_link($path)||is_link(dirname($path))) throw new \RuntimeException('Invalid SQLite path');
        return $parent.'/'.basename($path);
    }
    private function name(): string
    {
        $name=(string)($this->connection['database']??'');
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,62}$/D',$name)||in_array(strtolower($name),['postgres','template0','template1','mysql','sys','information_schema','performance_schema'],true)) throw new \RuntimeException('Dedicated non-system database required');
        return $name;
    }
    private function pgEnv(): array
    {
        return ['PGHOST'=>(string)($this->connection['host']??'127.0.0.1'),'PGPORT'=>(string)($this->connection['port']??5432),'PGUSER'=>(string)(getenv('BACKUP_DB_USERNAME')?:($this->connection['username']??'')),'PGPASSWORD'=>(string)(getenv('BACKUP_DB_PASSWORD')!==false?getenv('BACKUP_DB_PASSWORD'):($this->connection['password']??'')),'PGSSLMODE'=>(string)($this->connection['sslmode']??'prefer'),'PGCONNECT_TIMEOUT'=>'10'];
    }
    private function mysql(array $args, ?string $out, ?string $in): void
    {
        $dir=F::path($this->root,'storage/app/backups'); F::dir($dir);
        $file=$dir.'/.mysql-'.bin2hex(random_bytes(12)).'.cnf';
        $escape=static fn($v)=>'"'.strtr((string)$v,["\\"=>"\\\\",'"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r']).'"';
        $data="[client]\n";
        foreach (['host'=>'host','port'=>'port','user'=>'username','password'=>'password'] as $k=>$v) { $override=$k==='user'?getenv('BACKUP_DB_USERNAME'):($k==='password'?getenv('BACKUP_DB_PASSWORD'):false); $data.=$k.'='.$escape($override!==false?$override:($this->connection[$v]??''))."\n"; }
        F::write($file,$data);
        try { array_splice($args,1,0,['--defaults-file='.$file]); self::run($args,[],$out,$in); }
        finally { F::remove($file); }
    }
    public static function run(array $args, array $environment=[], ?string $output=null, ?string $input=null): void
    {
        if (!function_exists('proc_open')) throw new \RuntimeException('proc_open required');
        if (self::$processLock!==null) $args=array_merge(['flock','--exclusive','--nonblock',self::$processLock],$args);
        $err=tmpfile(); if ($err===false) throw new \RuntimeException('Cannot create process log');
        $stdout=$output!==null?['file',$output,'wb']:['file','/dev/null','w'];
        $proc=proc_open($args,[0=>['file',$input??'/dev/null','rb'],1=>$stdout,2=>$err],$pipes,null,array_merge(getenv(),$environment),['bypass_shell'=>true]);
        if (!is_resource($proc)) { fclose($err); throw new \RuntimeException('Cannot start database/validation command'); }
        $exit=proc_close($proc); rewind($err); $detail=trim((string)stream_get_contents($err,4096)); fclose($err);
        if ($exit!==0) throw new \RuntimeException(basename((string)$args[0]).' failed (exit '.$exit.'): '.$detail);
    }
}
