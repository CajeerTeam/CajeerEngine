<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Content\ContentEntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentEntryController
{
    private ContentEntryRepository $repository;

    public function __construct(private string $rootPath)
    {
        $this->repository = new ContentEntryRepository($this->rootPath);
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        $type = (string) ($parameters['type'] ?? '');
        $items = $this->repository->list($type, [
            'status' => (string) $request->query->get('status', 'all'),
            'locale' => (string) $request->query->get('locale', ''),
            'q' => (string) $request->query->get('q', ''),
            'published_only' => filter_var($request->query->get('published_only', false), FILTER_VALIDATE_BOOLEAN),
        ]);

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'total' => count($items),
                'storage' => $this->repository->storage(),
                'type' => $type,
            ],
        ]);
    }

    /** @param array<string, mixed> $parameters */
    public function show(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->find((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry]);
    }

    /** @param array<string, mixed> $parameters */
    public function store(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->create((string) ($parameters['type'] ?? ''), $this->json($request));
        return new JsonResponse(['data' => $entry], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $parameters */
    public function update(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->update((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''), $this->json($request));
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry]);
    }

    /** @param array<string, mixed> $parameters */
    public function delete(Request $request, array $parameters): JsonResponse
    {
        if (!$this->repository->delete((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''))) {
            return $this->notFound();
        }
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<string, mixed> $parameters */
    public function publish(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->publish((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry]);
    }

    /** @param array<string, mixed> $parameters */
    public function unpublish(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->unpublish((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry]);
    }

    /** @param array<string, mixed> $parameters */
    public function archive(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->archive((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry]);
    }

    /** @param array<string, mixed> $parameters */
    public function revisions(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->find((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        if ($entry === null) {
            return $this->notFound();
        }
        $items = $this->repository->revisions((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items), 'storage' => $this->repository->storage()]]);
    }

    /** @param array<string, mixed> $parameters */
    public function restoreRevision(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->restoreRevision(
            (string) ($parameters['type'] ?? ''),
            (string) ($parameters['id'] ?? ''),
            (int) ($parameters['revision'] ?? 0),
        );
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry]);
    }

    /** @param array<string, mixed> $parameters */
    public function localizations(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->find((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        if ($entry === null) {
            return $this->notFound();
        }
        $items = $this->repository->localizations((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''));
        return new JsonResponse(['data' => $items, 'meta' => ['total' => count($items), 'storage' => $this->repository->storage()]]);
    }

    /** @param array<string, mixed> $parameters */
    public function storeLocalization(Request $request, array $parameters): JsonResponse
    {
        $entry = $this->repository->createLocalization((string) ($parameters['type'] ?? ''), (string) ($parameters['id'] ?? ''), $this->json($request));
        if ($entry === null) {
            return $this->notFound();
        }
        return new JsonResponse(['data' => $entry], Response::HTTP_CREATED);
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
                'code' => 'content_entry_not_found',
                'message' => 'Запись контента не найдена.',
            ],
        ], Response::HTTP_NOT_FOUND);
    }
}
