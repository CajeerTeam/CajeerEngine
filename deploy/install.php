<?php
// Passwords arrive on stdin, never in command arguments, logs or an exported environment.
declare(strict_types=1);
use CajeerEngine\Installer\InstallerService;
use CajeerEngine\Support\EnvironmentFile;

// Only this exception's deliberately constructed, non-secret message may be logged.
final class DeploymentInstallFailure extends RuntimeException {}

function deploymentClientVersion(string $binary, string $host, int $port): int
{
    // Debian/Ubuntu pg_wrapper otherwise selects pg_dump/pg_restore according
    // to a local cluster, which may be older than the actual remote server.
    $env=array_merge(getenv(),['PGHOST'=>$host,'PGPORT'=>(string)$port,'LC_ALL'=>'C']);
    $process=proc_open([$binary,'--version'],[
        0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w'],
    ],$pipes,null,$env,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new DeploymentInstallFailure('Cannot start PostgreSQL client: '.$binary);
    $output=stream_get_contents($pipes[1],1024);
    fclose($pipes[1]);
    $exit=proc_close($process);
    if ($exit!==0 || !is_string($output) || !preg_match('/PostgreSQL\)\s+(\d+)/',$output,$m)) {
        throw new DeploymentInstallFailure('Cannot determine PostgreSQL client version: '.$binary);
    }
    return (int)$m[1];
}

function deploymentCheckClients(string $host, int $port, int $serverMajor): void
{
    foreach (['pg_dump','pg_restore'] as $binary) {
        $major=deploymentClientVersion($binary,$host,$port);
        if ($major<$serverMajor) {
            throw new DeploymentInstallFailure($binary.' major '.$major.' is older than server major '.$serverMajor);
        }
    }
}

function deploymentInstallDiagnostic(string $phase, Throwable $e): string
{
    // Do not log raw exception messages, traces, DSNs, options or install-state JSON.
    // Source location + SQLSTATE identify the failing code without exposing credentials.
    $detail=$e instanceof DeploymentInstallFailure ? ' '.$e->getMessage() : '';
    for ($cause=$e;$cause!==null;$cause=$cause->getPrevious()) {
        if ($cause instanceof PDOException && preg_match('/^[0-9A-Z]{5}$/D',(string)$cause->getCode())) {
            $detail.=' sqlstate='.$cause->getCode();
            break;
        }
    }
    return 'Installation failed: phase='.$phase.' type='.$e::class.' at='.basename($e->getFile()).':'.$e->getLine().$detail.PHP_EOL;
}

function deploymentInstall(): int
{
    $phase='options';
    try {
        umask(0077);
        ini_set('display_errors','0');
        ini_set('log_errors','0');
        $root=getcwd();
        $options=json_decode(stream_get_contents(STDIN,65536),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($options) || ($options['db']??null)!=='pgsql' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/D',(string)($options['db_password']??''))) throw new DeploymentInstallFailure('Invalid deployment options');
        foreach (['db_host','db_name','db_user'] as $field) if (!preg_match('/^[A-Za-z0-9_.-]+$/D',(string)($options[$field]??''))) throw new DeploymentInstallFailure('Invalid database option');
        if (!is_int($options['db_port']??null) || $options['db_port']<1 || $options['db_port']>65535 || !in_array($options['db_sslmode']??null,['require','verify-ca','verify-full','disable'],true)) throw new DeploymentInstallFailure('Invalid database port or TLS mode');
        $phase='autoload';
        require $root.'/vendor/autoload.php';
        $phase='fresh-install';
        if (is_file($root.'/.env') || is_file($root.'/storage/app/installed.lock')) throw new DeploymentInstallFailure('Installation already exists');
        // Require a dedicated empty database. Never seed an existing production database.
        $phase='database-connect';
        $pdo=new PDO('pgsql:host='.$options['db_host'].';port='.$options['db_port'].';dbname='.$options['db_name'].';connect_timeout=5;sslmode='.$options['db_sslmode'],$options['db_user'],$options['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $phase='database-empty';
        if ((int)$pdo->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_%' AND c.relkind IN ('r','p','v','m','f','S')")->fetchColumn()!==0) throw new DeploymentInstallFailure('Database not empty');
        $phase='database-version';
        $serverVersion=(int)$pdo->query('SHOW server_version_num')->fetchColumn();
        if ($serverVersion<170000) throw new DeploymentInstallFailure('PostgreSQL 17+ required');
        $phase='database-client-version';
        deploymentCheckClients($options['db_host'],$options['db_port'],intdiv($serverVersion,10000));
        $phase='database-role';
        if ($pdo->query("SELECT CASE WHEN r.rolcreatedb AND d.datdba=r.oid AND NOT r.rolsuper THEN 1 ELSE 0 END FROM pg_roles r JOIN pg_database d ON d.datname=current_database() WHERE r.rolname=current_user")->fetchColumn()!=1) throw new DeploymentInstallFailure('Dedicated non-superuser database owner with CREATEDB required');
        $pdo=null;
        $phase='database-maintenance';
        $maintenance=new PDO('pgsql:host='.$options['db_host'].';port='.$options['db_port'].';dbname=postgres;connect_timeout=5;sslmode='.$options['db_sslmode'],$options['db_user'],$options['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $maintenance->query('SELECT 1'); $maintenance=null;
        $phase='application-install';
        $result=(new InstallerService($root))->install($options+['production'=>true,'cache_driver'=>'file','queue_driver'=>'pgsql','search_driver'=>'pgsql','source'=>'production-deploy']);
        if (!($result['ok']??false) || !($result['final']['ok']??false) || !is_file($root.'/storage/app/installed.lock')) throw new DeploymentInstallFailure('Installation checks failed');
        $phase='runtime-configuration';
        (new EnvironmentFile($root.'/.env'))->setMany([
            'SCHEDULER_DRIVER'=>'pgsql','UPDATE_TRUSTED_PUBLIC_KEY_FILE'=>'/etc/cajeerengine-deploy/release.pub',
            'BACKUP_KEY_FILE'=>'/etc/cajeerengine-deploy/backup.key','OBSERVABILITY_METRICS_TOKEN'=>bin2hex(random_bytes(32)),
            'UPDATE_WEB_UPGRADE_ENABLED'=>'false',
        ]);
        if (!chmod($root.'/.env',0600)) throw new DeploymentInstallFailure('Cannot protect runtime environment');
        (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($root.'/.env');
        $phase='database-recovery';
        (new \CajeerEngine\Backup\DatabaseBackup($root,new \CajeerEngine\Runtime\ConfigRepository($root)))->assertRecoveryReady();
        echo "Installation complete\n";
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR,deploymentInstallDiagnostic($phase,$e));
        return 1;
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) exit(deploymentInstall());
