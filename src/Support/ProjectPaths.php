<?php

declare(strict_types=1);

namespace Cajeer\Engine\Support;

final class ProjectPaths
{
    private function __construct(
        public readonly string $root
    ) {
    }

    public static function fromRoot(string $root): self
    {
        return new self(rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR));
    }

    public function path(string $relative = ''): string
    {
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;
    }

    public function storage(string $relative = ''): string
    {
        return $this->path('storage' . ($relative === '' ? '' : DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR)));
    }

    public function config(string $relative = ''): string
    {
        return $this->path('config' . ($relative === '' ? '' : DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR)));
    }
}
