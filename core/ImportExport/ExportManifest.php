<?php

declare(strict_types=1);

namespace CajeerEngine\ImportExport;

final readonly class ExportManifest
{
    /** @param list<string> $sections */
    public function __construct(
        public string $id,
        public string $engineVersion,
        public array $sections,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
