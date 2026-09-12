<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

final readonly class BlockDefinition
{
    /** @param list<FieldDefinition> $fields */
    public function __construct(
        public string $handle,
        public string $name,
        public array $fields = [],
    ) {
    }
}
