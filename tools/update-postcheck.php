<?php
// Invoked in a fresh process so migrations and health checks use the newly installed code.
declare(strict_types=1);
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Server\ServerDoctorService;
use CajeerEngine\Admin\AdminAssetPublisher;
$root=dirname(__DIR__);
chdir($root);
try {
    require $root.'/vendor/autoload.php';
    if (is_file($root.'/.env')) (new Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv($root.'/.env');
    $config=new ConfigRepository($root); $db=new DatabaseManager($config);
    $runner=new MigrationRunner($db->connection(),$root.'/migrations');
    if (($argv[1]??'')==='migrate') $runner->run($db->migrationDriver());
    $status=$runner->status($db->migrationDriver());
    if ($status['pending']!==[]||$status['failed']!==[]||array_filter($status['applied'],static fn($r)=>!($r['checksum_ok']??false))) throw new RuntimeException('Migration verification failed');
    $health=$db->health(); if (!($health['ok']??false)||!($health['schema_ready']??false)) throw new RuntimeException('DB schema not ready');
    (new CajeerEngine\Public\RouteIndex($root,$db))->rebuild();
    if (!(new AdminAssetPublisher($root))->manifest()['ready']) throw new RuntimeException('Admin assets invalid');
    $doctor=(new ServerDoctorService($root))->run(false); if (!($doctor['ok']??false)) throw new RuntimeException('Doctor failed');
    $app=CajeerEngine\Kernel\Application::boot($root);
    $response=$app->handle(Symfony\Component\HttpFoundation\Request::create('/api/v1/health','GET'));
    if ($response->getStatusCode()!==200) throw new RuntimeException('HTTP health smoke failed');
    echo "Post-update checks passed\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
