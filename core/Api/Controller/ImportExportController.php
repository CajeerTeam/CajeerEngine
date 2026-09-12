<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\ImportExport\ImportExportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class ImportExportController
{
    public function __construct(private ImportExportService $service)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function exports(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->exports()]);
    }

    /** @param array<string, mixed> $parameters */
    public function export(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $sections = is_array($payload['sections'] ?? null) ? array_values(array_map('strval', $payload['sections'])) : [];
        $filename = isset($payload['filename']) ? (string) $payload['filename'] : null;
        return new JsonResponse(['data' => $this->service->createExport($sections, $filename)], 201);
    }

    /** @param array<string, mixed> $parameters */
    public function diff(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $file = (string) ($payload['file'] ?? '');
        if ($file === '') {
            return new JsonResponse(['error' => ['code' => 'import_file_required', 'message' => 'Поле file обязательно.']], 422);
        }
        return new JsonResponse(['data' => $this->service->diff($file, $payload)]);
    }

    /** @param array<string, mixed> $parameters */
    public function import(Request $request, array $parameters): JsonResponse
    {
        $payload = $this->json($request);
        $file = (string) ($payload['file'] ?? '');
        if ($file === '') {
            return new JsonResponse(['error' => ['code' => 'import_file_required', 'message' => 'Поле file обязательно.']], 422);
        }
        return new JsonResponse(['data' => $this->service->import($file, $payload)]);
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
