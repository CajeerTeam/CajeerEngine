<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Database\MigrationRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class DatabaseController
{
    public function __construct(private DatabaseManager $database, private string $rootPath)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $health = $this->database->health();
        return new JsonResponse(['data' => $health], ($health['ok'] ?? false) === true ? 200 : 503);
    }

    public function migrations(Request $request): JsonResponse
    {
        try {
            $runner = new MigrationRunner($this->database->connection(), $this->rootPath . '/migrations');
            $status = $runner->status($this->database->migrationDriver());
            return new JsonResponse([
                'data' => [
                    'driver' => $status['driver'],
                    'applied_count' => count($status['applied']),
                    'pending_count' => count($status['pending']),
                    'failed_count' => count($status['failed']),
                    'applied' => $status['applied'],
                    'pending' => $status['pending'],
                    'failed' => $status['failed'],
                ],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => [
                    'code' => 'database_migrations_unavailable',
                    'message' => 'Не удалось получить состояние миграций.',
                    'details' => [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                    'request_id' => (string) $request->attributes->get('request_id', ''),
                ],
            ], 503);
        }
    }
}
