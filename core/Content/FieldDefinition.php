<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

final readonly class FieldDefinition
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $handle,
        public string $type,
        public string $label,
        public bool $required = false,
        public bool $localized = false,
        public array $settings = [],
    ) {
    }
}
