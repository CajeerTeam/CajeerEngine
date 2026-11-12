<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class JsonBodyMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return $next($request);
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        $path = rawurldecode($request->getPathInfo());
        if (!str_starts_with($path, '/api/')) { return $next($request); }
        if ($path === '/api/v1/media' && $request->getMethod() === 'POST' && str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            return $next($request);
        }
        $content = trim($request->getContent());
        if (strlen($content) > 40 * 1024 * 1024) { return new JsonResponse(['error' => ['code' => 'body_too_large', 'message' => 'Request body too large.']], 413); }

        if ($content === '') {
            $request->attributes->set('json', []);
            return $next($request);
        }

        if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
            return new JsonResponse(['error' => ['code' => 'unsupported_media_type', 'message' => 'Expected application/json.']], 415);
        }

        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Тело запроса должно быть валидным JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded) || !str_starts_with($content, '{')) {
            throw new \InvalidArgumentException('JSON body должен быть объектом или массивом.');
        }

        $request->attributes->set('json', $decoded);
        return $next($request);
    }
}
