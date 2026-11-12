<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Runtime\RuntimeLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ConfigRepository $config,
        private RuntimeLogger $logger,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->jsonError($request, 'validation_failed', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if ($e instanceof \PDOException) { return $this->jsonError($request, 'internal_error', 'Внутренняя ошибка базы данных.', 500); }
            $status = str_contains($message, 'не найден') ? Response::HTTP_NOT_FOUND : Response::HTTP_BAD_REQUEST;
            return $this->jsonError($request, $status === Response::HTTP_NOT_FOUND ? 'not_found' : 'runtime_error', $message, $status);
        } catch (\Throwable $e) {
            $requestId = (string) $request->attributes->get('request_id', '');
            $this->logger->error('Unhandled HTTP exception', [
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $payload = [
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Внутренняя ошибка CajeerEngine.',
                    'request_id' => $requestId,
                ],
            ];

            if (in_array($this->config->string('app.env'), ['local','dev','testing'], true) && $this->config->bool('app.debug')) {
                $payload['error']['debug'] = [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }

            return new JsonResponse($payload, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function jsonError(Request $request, string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => (string) $request->attributes->get('request_id', ''),
            ],
        ], $status);
    }
}
