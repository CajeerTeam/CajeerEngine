<?php

declare(strict_types=1);

namespace CajeerEngine\Storage;

use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\Uuid;

final readonly class StorageManager
{
    public function __construct(private string $rootPath, private ConfigRepository $config)
    {
    }

    public function driver(): string
    {
        return $this->config->string('storage.default', 'local');
    }


    public function disk(?string $name = null): StorageDiskInterface
    {
        $name = $name !== null && $name !== '' ? $name : $this->driver();
        return match ($name) {
            'local' => new LocalDisk($this->localRoot()),
            's3' => new S3CompatibleDisk($this->config->array('storage.disks.s3', [])),
            default => throw new \InvalidArgumentException('Неподдерживаемый storage driver: ' . $name),
        };
    }

    public function localRoot(): string
    {
        $configured = $this->config->string('storage.disks.local.root', 'storage/app');
        if (str_starts_with($configured, '/')) {
            return rtrim($configured, '/');
        }

        return rtrim($this->rootPath . '/' . trim($configured, '/'), '/');
    }

    public function publicUploadsRoot(): string
    {
        return $this->rootPath . '/public/uploads';
    }

    public function publicUploadsUrl(): string
    {
        return rtrim($this->config->string('storage.public_uploads_url', '/uploads'), '/');
    }

    public function writePublicUpload(string $relativePath, string $contents): string
    {
        $relativePath = $this->normalizePath($relativePath);
        $fullPath = $this->publicUploadsRoot() . '/' . $relativePath;
        $this->ensureDirectory(dirname($fullPath));

        if (file_put_contents($fullPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать media-файл: ' . $fullPath);
        }

        return $fullPath;
    }

    public function deletePublicUpload(string $relativePath): bool
    {
        $relativePath = $this->normalizePath($relativePath);
        $fullPath = $this->publicUploadsRoot() . '/' . $relativePath;
        if (!is_file($fullPath)) {
            return false;
        }

        return @unlink($fullPath);
    }

    public function publicUrl(string $relativePath): string
    {
        return $this->publicUploadsUrl() . '/' . $this->normalizePath($relativePath);
    }

    public function makeRelativeUploadPath(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension ?? '') ?: 'bin';
        $date = gmdate('Y/m');

        return $date . '/' . Uuid::v4() . '.' . $extension;
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $localRoot = $this->localRoot();
        $uploads = $this->publicUploadsRoot();
        $tmpProbe = $localRoot . '/.storage-probe-' . bin2hex(random_bytes(4));
        $writeOk = false;
        $deleteOk = false;

        try {
            $this->ensureDirectory($localRoot);
            $writeOk = file_put_contents($tmpProbe, 'ok', LOCK_EX) !== false;
            if ($writeOk) {
                $deleteOk = @unlink($tmpProbe);
            }
        } catch (\Throwable) {
            $writeOk = false;
        }

        $driverProbe = ['ok' => false, 'error' => null];
        try {
            $probePath = '.storage-probe-' . bin2hex(random_bytes(4));
            $disk = $this->disk();
            $disk->put($probePath, 'ok');
            $driverProbe['ok'] = $disk->exists($probePath);
            $disk->delete($probePath);
        } catch (\Throwable $e) {
            $driverProbe['error'] = $e->getMessage();
        }

        return [
            'driver' => $this->driver(),
            'driver_probe' => $driverProbe,
            'local_root' => $localRoot,
            'local_root_exists' => is_dir($localRoot),
            'local_root_writable' => is_writable($localRoot),
            'local_probe_write' => $writeOk,
            'local_probe_delete' => $deleteOk,
            'public_uploads_root' => $uploads,
            'public_uploads_exists' => is_dir($uploads),
            'public_uploads_writable' => is_dir($uploads) ? is_writable($uploads) : is_writable(dirname($uploads)),
            'public_uploads_url' => $this->publicUploadsUrl(),
            's3_configured' => $this->s3Configured(),
        ];
    }

    private function s3Configured(): bool
    {
        return $this->config->string('storage.disks.s3.endpoint', '') !== ''
            && $this->config->string('storage.disks.s3.bucket', '') !== ''
            && $this->config->string('storage.disks.s3.access_key', '') !== '';
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Не удалось создать директорию: ' . $path);
        }
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = trim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $part) ?? $part;
        }

        return implode('/', $parts);
    }
}
