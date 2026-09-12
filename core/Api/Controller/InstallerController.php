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
        $payload = $this->json($request);
        return new JsonResponse(['data' => $this->installer->install($payload)], 201);
    }

    /** @return array<string, mixed> */
    private function json(Request $request): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) {
            return $parsed;
        }
        $body = trim($request->getContent());
        if ($body === '') {
            return [];
        }
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        return is_array($payload) ? $payload : [];
    }
}
