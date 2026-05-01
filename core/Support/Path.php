<?php

declare(strict_types=1);

namespace Cajeer\Support;

final class Path
{
    public function __construct(private readonly string $basePath) {}

    public function base(string $path = ''): string
    {
        return rtrim($this->basePath . '/' . ltrim($path, '/'), '/');
    }

    public function storage(string $path = ''): string
    {
        return $this->base('storage/' . ltrim($path, '/'));
    }

    public function public(string $path = ''): string
    {
        return $this->base('public/' . ltrim($path, '/'));
    }
}
