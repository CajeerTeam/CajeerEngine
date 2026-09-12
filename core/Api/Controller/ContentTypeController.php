<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Content\ContentSchemaGenerator;
use CajeerEngine\Content\ContentTypeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentTypeController
{
    private ContentTypeRepository $repository;

    public function __construct(private string $rootPath)
    {
        $this->repository = new ContentTypeRepository($this->rootPath);
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->repository->all();

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'total' => count($items),
                'storage' => $this->repository->storage(),
            ],
        ]);
    }

    /** @param array<string, mixed> $parameters */
    public function show(Request $request, array $parameters): JsonResponse
    {
        $handle = (string) ($parameters['handle'] ?? '');
        $item = $this->repository->find($handle);
        if ($item === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $item]);
    }

    /** @param array<string, mixed> $parameters */
    public function schema(Request $request, array $parameters): JsonResponse
    {
        $handle = (string) ($parameters['handle'] ?? '');
        $item = $this->repository->find($handle);
        if ($item === null) {
            return $this->notFound();
        }

        return new JsonResponse([
            'data' => [
                'content_type' => $item,
                'json_schema' => (new ContentSchemaGenerator())->jsonSchema($item),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->json($request);
        $item = $this->repository->create($payload);

        return new JsonResponse(['data' => $item], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $parameters */
    public function update(Request $request, array $parameters): JsonResponse
    {
        $handle = (string) ($parameters['handle'] ?? '');
        $payload = $this->json($request);
        $item = $this->repository->update($handle, $payload);

        return new JsonResponse(['data' => $item]);
    }

    /** @param array<string, mixed> $parameters */
    public function delete(Request $request, array $parameters): JsonResponse
    {
        $handle = (string) ($parameters['handle'] ?? '');
        if (!$this->repository->delete($handle)) {
            return $this->notFound();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
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
                'code' => 'content_type_not_found',
                'message' => 'Тип контента не найден.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }
}
