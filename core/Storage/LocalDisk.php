<?php

declare(strict_types=1);

namespace CajeerEngine\Storage;

use Symfony\Component\Filesystem\Filesystem;

final readonly class LocalDisk implements StorageDiskInterface
{
    public function __construct(private string $root, private Filesystem $filesystem = new Filesystem())
    {
    }

    public function put(string $path, string $contents): void
    {
        $fullPath = $this->fullPath($path);
        $this->filesystem->mkdir(dirname($fullPath));
        file_put_contents($fullPath, $contents);
    }

    public function get(string $path): string
    {
        $contents = file_get_contents($this->fullPath($path));
        if ($contents === false) {
            throw new \RuntimeException("Файл не прочитан: {$path}");
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function delete(string $path): void
    {
        $this->filesystem->remove($this->fullPath($path));
    }

    private function fullPath(string $path): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($path, '/');
    }
}
