<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class OpenApiLinter
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return list<CheckResult> */
    public function lint(): array
    {
        $path = $this->rootPath . '/api/openapi.yaml';
        if (!is_file($path)) {
            return [CheckResult::fail('api', 'openapi.exists', 'api/openapi.yaml отсутствует.')];
        }

        $content = (string) file_get_contents($path);
        $checks = [];
        $checks[] = str_contains($content, 'openapi: 3.1.')
            ? CheckResult::pass('api', 'openapi.version', 'OpenAPI 3.1.x указан.')
            : CheckResult::fail('api', 'openapi.version', 'OpenAPI должен быть 3.1.x.');

        foreach (['/health', '/system', '/content-types', '/auth/login', '/extensions', '/import-export/export', '/rc/readiness'] as $route) {
            $full = '/api/v1' . $route;
            $present = str_contains($content, $route . ':') || str_contains($content, '"' . $route . '"') || str_contains($content, $full . ':') || str_contains($content, '"' . $full . '"');
            $checks[] = $present
                ? CheckResult::pass('api', 'route.' . $full, 'OpenAPI содержит ' . $full)
                : CheckResult::fail('api', 'route.' . $full, 'OpenAPI не содержит ' . $full);
        }

        $operations = preg_match_all('/^\s{2,}(get|post|put|patch|delete):\s*$/mi', $content);
        $operationIds = preg_match_all('/operationId:\s*[A-Za-z0-9_\-]+/i', $content);
        $checks[] = $operations > 0
            ? CheckResult::pass('api', 'operations.present', 'В OpenAPI найдены операции.', ['operations' => $operations])
            : CheckResult::fail('api', 'operations.present', 'В OpenAPI не найдены HTTP-операции.');
        $checks[] = $operationIds >= max(1, (int) floor($operations * 0.7))
            ? CheckResult::pass('api', 'operation_ids.coverage', 'Большинство операций имеет operationId.', ['operations' => $operations, 'operation_ids' => $operationIds])
            : CheckResult::warn('api', 'operation_ids.coverage', 'Часть операций не имеет operationId.', ['operations' => $operations, 'operation_ids' => $operationIds]);

        $checks[] = str_contains($content, 'ErrorResponse') || str_contains($content, 'error:')
            ? CheckResult::pass('api', 'errors.documented', 'Ошибки API описаны в контракте.')
            : CheckResult::warn('api', 'errors.documented', 'OpenAPI не содержит явного общего ErrorResponse.');

        return $checks;
    }
}
