<?php

declare(strict_types=1);
namespace CajeerEngine\Http\Middleware;

use CajeerEngine\Api\ApiContract;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApiContractMiddleware implements MiddlewareInterface
{
    public function __construct(private ApiContract $contract) {}
    public function process(Request $request, callable $next): Response
    {
        $operation = $this->contract->operation((string) $request->attributes->get('matched_route', ''));
        if ($operation === null) return $next($request);
        $body = $operation['requestBody'] ?? null;
        if (!is_array($body)) return $next($request);
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_starts_with($contentType, 'multipart/form-data') && isset($body['content']['multipart/form-data'])) {
            $file = $request->files->get('file');
            if (!is_object($file) || !method_exists($file, 'isValid') || !$file->isValid()) return $this->error($request, ['$.file: valid file required']);
            return $next($request);
        }
        $content = trim($request->getContent());
        if ($content === '') {
            if ($body['required'] ?? false) return $this->error($request, ['$: request body required']);
            return $next($request);
        }
        $schema = $body['content']['application/json']['schema'] ?? null;
        if (!is_array($schema)) return $next($request);
        $value = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        $errors = $this->contract->validate($value, $schema);
        return $errors === [] ? $next($request) : $this->error($request, $errors);
    }
    private function error(Request $request, array $errors): JsonResponse
    {
        return new JsonResponse(['error'=>['code'=>'validation_failed','message'=>'Запрос не соответствует OpenAPI.', 'details'=>$errors, 'request_id'=>(string)$request->attributes->get('request_id','')]], 422);
    }
}
