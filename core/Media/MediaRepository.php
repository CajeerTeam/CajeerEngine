<?php

declare(strict_types=1);

namespace CajeerEngine\Media;

use CajeerEngine\Storage\StorageManager;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;

final readonly class MediaRepository
{
    public function __construct(private string $rootPath, private StorageManager $storage)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(string $query = ''): array
    {
        $items = $this->read()['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $query = strtolower(trim($query));
        if ($query !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($query): bool {
                $haystack = strtolower((string) ($item['filename'] ?? '') . ' ' . (string) ($item['title'] ?? '') . ' ' . (string) ($item['alt'] ?? ''));
                return str_contains($haystack, $query);
            }));
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return $items;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createFromBase64(array $payload): array
    {
        $filename = $this->sanitizeFilename((string) ($payload['filename'] ?? 'upload.bin'));
        $base64 = (string) ($payload['content_base64'] ?? '');
        if ($base64 === '') {
            throw new \InvalidArgumentException('Поле content_base64 обязательно для JSON upload.');
        }

        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $contents = base64_decode($base64, true);
        if ($contents === false) {
            throw new \InvalidArgumentException('content_base64 должен быть валидной base64-строкой.');
        }

        return $this->store($filename, $contents, [
            'mime_type' => (string) ($payload['mime_type'] ?? $this->detectMime($filename, $contents)),
            'title' => (string) ($payload['title'] ?? ''),
            'alt' => (string) ($payload['alt'] ?? ''),
        ]);
    }

    /** @param object $uploadedFile @param array<string, mixed> $payload @return array<string, mixed> */
    public function createFromUploadedFile(object $uploadedFile, array $payload = []): array
    {
        if (!method_exists($uploadedFile, 'getClientOriginalName') || !method_exists($uploadedFile, 'getPathname')) {
            throw new \InvalidArgumentException('Передан неподдерживаемый объект upload-файла.');
        }

        $filename = $this->sanitizeFilename((string) $uploadedFile->getClientOriginalName());
        $path = (string) $uploadedFile->getPathname();
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new \RuntimeException('Не удалось прочитать загруженный файл.');
        }

        $mime = method_exists($uploadedFile, 'getClientMimeType') ? (string) $uploadedFile->getClientMimeType() : $this->detectMime($filename, $contents);
        return $this->store($filename, $contents, [
            'mime_type' => $mime,
            'title' => (string) ($payload['title'] ?? ''),
            'alt' => (string) ($payload['alt'] ?? ''),
        ]);
    }

    public function delete(string $id): bool
    {
        $payload = $this->read();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $deleted = null;
        $items = array_values(array_filter($items, function (array $item) use ($id, &$deleted): bool {
            if (($item['id'] ?? null) === $id) {
                $deleted = $item;
                return false;
            }
            return true;
        }));

        if ($deleted === null) {
            return false;
        }

        $this->storage->deletePublicUpload((string) ($deleted['path'] ?? ''));
        $payload['items'] = $items;
        $payload['updated_at'] = $this->now();
        $this->file()->writeObject($payload);
        return true;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $items = $this->all();
        $bytes = 0;
        $missing = 0;
        foreach ($items as $item) {
            $bytes += (int) ($item['size'] ?? 0);
            $path = $this->rootPath . '/public/uploads/' . ltrim((string) ($item['path'] ?? ''), '/');
            if (!is_file($path)) {
                $missing++;
            }
        }

        return [
            'items' => count($items),
            'bytes' => $bytes,
            'missing_files' => $missing,
            'index_file' => $this->indexPath(),
            'public_root' => $this->storage->publicUploadsRoot(),
            'public_url' => $this->storage->publicUploadsUrl(),
        ];
    }

    /** @param array<string, string> $meta @return array<string, mixed> */
    private function store(string $filename, string $contents, array $meta): array
    {
        $this->assertAllowed($filename, (string) $meta['mime_type'], strlen($contents));
        $relativePath = $this->storage->makeRelativeUploadPath($filename);
        $fullPath = $this->storage->writePublicUpload($relativePath, $contents);
        $now = $this->now();
        $item = [
            'id' => Uuid::v4(),
            'filename' => $filename,
            'path' => $relativePath,
            'url' => $this->storage->publicUrl($relativePath),
            'mime_type' => (string) $meta['mime_type'],
            'size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'title' => (string) $meta['title'],
            'alt' => (string) $meta['alt'],
            'storage' => 'public-local',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (str_starts_with((string) $meta['mime_type'], 'image/')) {
            $size = @getimagesize($fullPath);
            if (is_array($size)) {
                $item['width'] = (int) $size[0];
                $item['height'] = (int) $size[1];
            }
        }

        $payload = $this->read();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $items[] = $item;
        $payload['items'] = $items;
        $payload['updated_at'] = $now;
        $this->file()->writeObject($payload);

        return $item;
    }

    private function assertAllowed(string $filename, string $mimeType, int $size): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowSvg = filter_var(getenv('MEDIA_ALLOW_SVG') ?: false, FILTER_VALIDATE_BOOLEAN);
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'zip'];
        if ($allowSvg) {
            $allowedExtensions[] = 'svg';
        }
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('Расширение файла не разрешено: .' . $extension);
        }
        if ($extension === 'svg' && !$allowSvg) {
            throw new \InvalidArgumentException('SVG upload отключён по умолчанию из-за XSS-рисков. Включите MEDIA_ALLOW_SVG=true только после настройки sanitization/CSP.');
        }

        $allowedMimePrefixes = ['text/'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'application/zip', 'application/x-zip-compressed'];
        if ($allowSvg) {
            $allowedMimes[] = 'image/svg+xml';
        }
        $mimeAllowed = in_array($mimeType, $allowedMimes, true);
        foreach ($allowedMimePrefixes as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                $mimeAllowed = true;
            }
        }
        if (!$mimeAllowed) {
            throw new \InvalidArgumentException('MIME type не разрешён: ' . $mimeType);
        }

        $maxMb = max(1, (int) (getenv('MEDIA_MAX_UPLOAD_MB') ?: 25));
        $max = $maxMb * 1024 * 1024;
        if ($size <= 0 || $size > $max) {
            throw new \InvalidArgumentException('Размер файла должен быть от 1 байта до ' . $maxMb . ' МБ.');
        }
    }

    private function detectMime(string $filename, string $contents): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $contents) ?: '';
                finfo_close($finfo);
                if ($mime !== '') {
                    return $mime;
                }
            }
        }

        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'md' => 'text/markdown',
            default => 'text/plain',
        };
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = trim($filename) ?: 'upload.bin';
        $name = pathinfo($filename, PATHINFO_FILENAME) ?: 'upload';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');
        $name = trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name), '-_.') ?: 'upload';
        $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: 'bin';

        return $name . '.' . $ext;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return $this->file()->readObject(['items' => [], 'updated_at' => null]);
    }

    private function file(): JsonFile
    {
        return new JsonFile($this->indexPath());
    }

    private function indexPath(): string
    {
        return $this->rootPath . '/storage/app/media.json';
    }

    private function now(): string
    {
        return gmdate('c');
    }
}
