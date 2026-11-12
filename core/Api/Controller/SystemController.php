<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Observability\SystemReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class SystemController
{
    public function __construct(private string $rootPath)
    {
    }

    public function operations(Request $request): JsonResponse
    {
        return new JsonResponse((new \CajeerEngine\Observability\OperationsMonitor($this->rootPath))->report(),200,['Cache-Control'=>'no-store']);
    }

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse((new SystemReport($this->rootPath))->toArray());
    }
}
