<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Installer\InstallerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class InstallerController
{
    public function __construct(private InstallerService $installer)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function requirements(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->installer->requirements()]);
    }

    /** @param array<string, mixed> $parameters */
    public function run(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'installer_cli_or_web_required', 'message' => 'Используйте /install с setup token или CLI installer.']], 403);
    }


}
