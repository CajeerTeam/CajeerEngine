<?php
// Passwords arrive on stdin, never in command arguments, logs or an exported environment.
declare(strict_types=1);
use CajeerEngine\Installer\InstallerService;
use CajeerEngine\Support\EnvironmentFile;
try {
    umask(0077);
    $root=getcwd();
    require $root.'/vendor/autoload.php';
    $options=json_decode(stream_get_contents(STDIN, 65536),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($options) || ($options['db']??null)!=='pgsql' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/D',(string)($options['db_password']??''))) throw new RuntimeException('Invalid deployment options');
    foreach (['db_host','db_name','db_user'] as $field) if (!preg_match('/^[A-Za-z0-9_.-]+$/D',(string)($options[$field]??''))) throw new RuntimeException('Invalid database option');
    if (is_file($root.'/.env') || is_file($root.'/storage/app/installed.lock')) throw new RuntimeException('Installation already exists');
    // Require a dedicated empty database. Never seed an existing production database.
    $pdo=new PDO('pgsql:host='.$options['db_host'].';port='.(int)$options['db_port'].';dbname='.$options['db_name'].';connect_timeout=5;sslmode='.$options['db_sslmode'],$options['db_user'],$options['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ((int)$pdo->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_%' AND c.relkind IN ('r','p','v','m','f','S')")->fetchColumn()!==0) throw new RuntimeException('Database not empty');
    $serverVersion=(int)$pdo->query('SHOW server_version_num')->fetchColumn();
    if ($serverVersion<170000) throw new RuntimeException('PostgreSQL 17+ required');
    $client=trim((string)shell_exec('pg_dump --version'));
    if (!preg_match('/PostgreSQL\) (\d+)/',$client,$m) || (int)$m[1]<intdiv($serverVersion,10000)) throw new RuntimeException('Matching or newer PostgreSQL client required');
    if ($pdo->query("SELECT CASE WHEN r.rolcreatedb AND d.datdba=r.oid AND NOT r.rolsuper THEN 1 ELSE 0 END FROM pg_roles r JOIN pg_database d ON d.datname=current_database() WHERE r.rolname=current_user")->fetchColumn()!=1) throw new RuntimeException('Dedicated non-superuser database owner with CREATEDB required');
    $pdo=null;
    $maintenance=new PDO('pgsql:host='.$options['db_host'].';port='.(int)$options['db_port'].';dbname=postgres;connect_timeout=5;sslmode='.$options['db_sslmode'],$options['db_user'],$options['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $maintenance->query('SELECT 1'); $maintenance=null;
    $result=(new InstallerService($root))->install($options+['production'=>true,'cache_driver'=>'file','queue_driver'=>'pgsql','search_driver'=>'pgsql','source'=>'production-deploy']);
    if (!($result['ok']??false) || !($result['final']['ok']??false) || !is_file($root.'/storage/app/installed.lock')) throw new RuntimeException('Installation checks failed');
    (new EnvironmentFile($root.'/.env'))->setMany([
        'SCHEDULER_DRIVER'=>'pgsql','UPDATE_TRUSTED_PUBLIC_KEY_FILE'=>'/etc/cajeerengine-deploy/release.pub',
        'BACKUP_KEY_FILE'=>'/etc/cajeerengine-deploy/backup.key','OBSERVABILITY_METRICS_TOKEN'=>bin2hex(random_bytes(32)),
        'UPDATE_WEB_UPGRADE_ENABLED'=>'false',
    ]);
    chmod($root.'/.env',0600);
    (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($root.'/.env');
    (new \CajeerEngine\Backup\DatabaseBackup($root,new \CajeerEngine\Runtime\ConfigRepository($root)))->assertRecoveryReady();
    echo "Installation complete\n";
} catch (Throwable $e) { fwrite(STDERR,"Installation failed; inspect database and application diagnostics before retrying.\n"); exit(1); }
