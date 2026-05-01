<?php

declare(strict_types=1);

namespace Cajeer\Themes;

final class ActiveTheme
{
    public function __construct(
        public readonly string $slug,
        public readonly string $path,
        public readonly array $manifest = [],
    ) {}
}
