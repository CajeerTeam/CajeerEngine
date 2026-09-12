<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

final readonly class ContentType
{
    /** @param list<FieldDefinition> $fields */
    public function __construct(
        public string $id,
        public string $handle,
        public string $name,
        public array $fields = [],
        public bool $localized = false,
        public bool $revisionable = true,
    ) {
    }
}
