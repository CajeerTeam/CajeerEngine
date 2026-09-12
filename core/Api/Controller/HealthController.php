<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\ProjectInfo;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class HealthController
{
    public function __construct(private string $rootPath)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $project = new ProjectInfo($this->rootPath);
        $config = new ConfigRepository($this->rootPath);
        $storageWritable = is_writable($this->rootPath . '/storage');
        $logsWritable = is_writable($this->rootPath . '/storage/logs') || is_writable($this->rootPath . '/storage');
        $appWritable = is_writable($this->rootPath . '/storage/app') || is_writable($this->rootPath . '/storage');
        $status = ($storageWritable && $logsWritable && $appWritable) ? 'ok' : 'degraded';

        return new JsonResponse([
            'status' => $status,
            'engine' => $project->title(),
            'version' => $project->version(),
            'engine_api_version' => $project->engineApiVersion(),
            'environment' => $config->string('app.env', 'production'),
            'php' => PHP_VERSION,
            'time' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'request_id' => (string) $request->attributes->get('request_id', ''),
            'checks' => [
                'storage_writable' => $storageWritable,
                'logs_writable' => $logsWritable,
                'app_storage_writable' => $appWritable,
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
                'pdo' => extension_loaded('pdo'),
                'openssl' => extension_loaded('openssl'),
                'config_loaded' => $config->has('app.name'),
            ],
        ], $status === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
