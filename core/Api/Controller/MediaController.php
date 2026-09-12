<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Media\MediaRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class MediaController
{
    public function __construct(private MediaRepository $media)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        $items = $this->media->all((string) $request->query->get('q', ''));
        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'total' => count($items),
                'diagnostics' => $this->media->diagnostics(),
            ],
        ]);
    }

    /** @param array<string, mixed> $parameters */
    public function show(Request $request, array $parameters): JsonResponse
    {
        $item = $this->media->find((string) ($parameters['id'] ?? ''));
        if ($item === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $item]);
    }

    /** @param array<string, mixed> $parameters */
    public function store(Request $request, array $parameters): JsonResponse
    {
        $file = $request->files->get('file');
        if (is_object($file)) {
            $item = $this->media->createFromUploadedFile($file, $this->json($request, false));
        } else {
            $item = $this->media->createFromBase64($this->json($request));
        }

        return new JsonResponse(['data' => $item], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $parameters */
    public function delete(Request $request, array $parameters): JsonResponse
    {
        if (!$this->media->delete((string) ($parameters['id'] ?? ''))) {
            return $this->notFound();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->media->diagnostics()]);
    }

    /** @return array<string, mixed> */
    private function json(Request $request, bool $strict = true): array
    {
        $parsed = $request->attributes->get('json');
        if (is_array($parsed)) {
            return $parsed;
        }

        $body = trim($request->getContent());
        if ($body === '') {
            return $strict ? [] : $request->request->all();
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Тело запроса должно быть валидным JSON: ' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JSON body должен быть объектом.');
        }

        return $payload;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'media_not_found',
                'message' => 'Media-файл не найден.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }
}
