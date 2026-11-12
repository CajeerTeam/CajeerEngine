<?php
declare(strict_types=1);
namespace CajeerEngine\Storage;

use CajeerEngine\Update\OperationFiles;

/** Local storage uses the same checked, durable filesystem operations as backup. */
final readonly class LocalDisk implements StorageDiskInterface
{
    public function __construct(private string $root) {}
    public function put(string $path,string $contents): void
    {
        $full=$this->fullPath($path);OperationFiles::write($full,$contents,0660);
    }
    public function get(string $path): string
    {
        $full=$this->fullPath($path);if(!is_file($full)||($data=file_get_contents($full))===false)throw new \RuntimeException('Storage file unavailable');return $data;
    }
    public function exists(string $path): bool{return is_file($this->fullPath($path));}
    public function delete(string $path): void{OperationFiles::remove($this->fullPath($path));}
    private function fullPath(string $path): string{return OperationFiles::path($this->root,$path);}
}
