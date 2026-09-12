<?php

declare(strict_types=1);

namespace CajeerEngine\Api\Controller;

use CajeerEngine\Search\SearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class SearchController
{
    public function __construct(private SearchService $search)
    {
    }

    /** @param array<string, mixed> $parameters */
    public function index(Request $request, array $parameters): JsonResponse
    {
        $query = (string) $request->query->get('q', '');
        $index = (string) $request->query->get('index', '');
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        return new JsonResponse($this->search->search($query, $index !== '' ? $index : null, $limit));
    }

    /** @param array<string, mixed> $parameters */
    public function reindex(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->search->reindex()]);
    }

    /** @param array<string, mixed> $parameters */
    public function diagnostics(Request $request, array $parameters): JsonResponse
    {
        return new JsonResponse(['data' => $this->search->diagnostics()]);
    }
}
