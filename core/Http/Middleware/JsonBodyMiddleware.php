<?php

declare(strict_types=1);

namespace CajeerEngine\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
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
        $content = trim($request->getContent());

        if ($content === '') {
            $request->attributes->set('json', []);
            return $next($request);
        }

        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            return $next($request);
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Тело запроса должно быть валидным JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('JSON body должен быть объектом или массивом.');
        }

        $request->attributes->set('json', $decoded);
        return $next($request);
    }
}
