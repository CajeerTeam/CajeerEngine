<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

interface SearchEngineInterface
{
    /** @return list<array<string, mixed>> */
    public function search(string $query, ?string $index = null, int $limit = 20): array;

    /** @param array<string, mixed> $document */
    public function index(string $index, string $id, array $document): void;

    public function delete(string $index, string $id): void;

    /** @return array<string, mixed> */
    public function diagnostics(): array;
}
