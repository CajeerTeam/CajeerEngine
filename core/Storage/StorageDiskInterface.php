<?php

declare(strict_types=1);

namespace CajeerEngine\Storage;

interface StorageDiskInterface
{
    public function put(string $path, string $contents): void;

    public function get(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): void;
}
