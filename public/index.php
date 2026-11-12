<?php

declare(strict_types=1);

use CajeerEngine\Kernel\Application;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__);
$installPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
require_once $root . '/core/Console/NativeCli.php';
require_once $root . '/core/Console/OperationsCli.php';
if (in_array($installPath,['/health/live','/health/ready','/metrics'],true)) {
    try { if($installPath!=='/health/live') { if(is_file($root.'/vendor/autoload.php'))require_once $root.'/vendor/autoload.php'; CajeerEngine\Console\OperationsCli::loadEnv($root); } }
    catch (Throwable) { http_response_code(503); header('Cache-Control: no-store'); exit; }
    [$code,$headers,$body]=CajeerEngine\Observability\ProbeEndpoint::response($root,$installPath,$_SERVER['REQUEST_METHOD']??'GET',$_SERVER['HTTP_AUTHORIZATION']??null);
    http_response_code($code);foreach($headers as $key=>$value)header($key.': '.$value);
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $body;exit;
}
$started=hrtime(true);$handled=false;
register_shutdown_function(static function()use($root,$installPath,$started,&$handled){
    if($handled)return;
    $status=http_response_code()?:200;
    $error=error_get_last();if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))$status=500;
    $route=str_starts_with($installPath,'/install')?'install':(str_starts_with($installPath,'/upgrade')?'upgrade':($status===503?'maintenance':'bootstrap'));
    (new CajeerEngine\Observability\Metrics($root))->observe('http',$route,intdiv($status,100).'xx',(hrtime(true)-$started)/1e9);
});

require_once $root . '/core/Update/OperationFiles.php';
require_once $root . '/core/Update/OperationLock.php';
try {
    $activityLease = CajeerEngine\Update\OperationLock::acquire($root, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_starts_with(rawurldecode($installPath), '/api/v1/import-export/'));
    if (CajeerEngine\Update\OperationLock::active($root) || is_file($root.'/storage/app/extensions/deployment.json') || is_file($root.'/storage/app/backup-restore.json')) throw new RuntimeException('Recovery in progress');
} catch (Throwable) {
    http_response_code(503); header('Retry-After: 60'); header('Cache-Control: no-store');
    echo 'Maintenance / recovery in progress. Use the CLI recovery tools.'; exit;
}

if ($installPath === '/install' || str_starts_with($installPath, '/install/')) {
    require __DIR__ . '/install/index.php';
    exit;
}

if ($installPath === '/upgrade' || str_starts_with($installPath, '/upgrade/')) {
    require __DIR__ . '/upgrade/index.php';
    exit;
}

$maintenancePath = $root . '/storage/app/maintenance.json';
if (is_file($maintenancePath) && !in_array($installPath, ['/api/v1/health', '/api/v1/auth/login', '/api/v1/auth/me', '/api/v1/auth/logout', '/api/v1/updates/maintenance', '/api/v1/updates/maintenance/off'], true)) {
    $payload = json_decode((string) file_get_contents($maintenancePath), true);
    $message = is_array($payload) ? (string) ($payload['message'] ?? 'Сайт временно недоступен из-за обслуживания.') : 'Сайт временно недоступен из-за обслуживания.';
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 120');
    echo json_encode([
        'error' => [
            'code' => 'maintenance_mode',
            'message' => $message,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code' => 'composer_autoload_missing',
            'message' => 'vendor/autoload.php не найден. Выполните composer install перед запуском CajeerEngine.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require $autoload;

if (is_file($root . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($root . '/.env');
}

try {
    $app = Application::boot($root);
    $response = $app->handle(Request::createFromGlobals());
    $handled=true;
} catch (Throwable $e) {
    $debug = in_array(getenv('APP_ENV'), ['local', 'dev', 'testing'], true) && filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
    $payload = [
        'error' => [
            'code' => 'kernel_boot_failed',
            'message' => 'Не удалось запустить CajeerEngine.',
        ],
    ];
    if ($debug) {
        $payload['error']['debug'] = [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
    $response = new JsonResponse($payload, 500);
}

$response->send();
