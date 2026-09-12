<?php

declare(strict_types=1);

use CajeerEngine\Kernel\Application;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__);
$installPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($installPath === '/install' || str_starts_with($installPath, '/install/')) {
    require __DIR__ . '/install/index.php';
    exit;
}

$maintenancePath = $root . '/storage/app/maintenance.json';
if (is_file($maintenancePath) && !str_starts_with($installPath, '/api/v1/health') && !str_starts_with($installPath, '/upgrade')) {
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
} catch (Throwable $e) {
    $debug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
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
