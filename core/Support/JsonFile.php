<?php

declare(strict_types=1);

namespace CajeerEngine\Support;

final readonly class JsonFile
{
    public function __construct(private string $path)
    {
    }

    /** @return array<string, mixed> */
    public function readObject(array $default = []): array
    {
        if (is_link($this->path)) throw new \RuntimeException('Refusing symbolic JSON file: '.$this->path);
        if (!is_file($this->path)) {
            if(file_exists($this->path))throw new \RuntimeException('Not a regular JSON file: '.$this->path);
            return $default;
        }

        $contents = file_get_contents($this->path);
        if ($contents === false || trim($contents) === '') {
            throw new \RuntimeException('JSON file is empty or unreadable: '.$this->path);
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('JSON-файл повреждён: ' . $this->path . ' — ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON-файл должен содержать объект: ' . $this->path);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    public function writeObject(array $data): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать директорию: ' . $directory);
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mode=is_file($this->path)?(fileperms($this->path)&0777):0660;
        \CajeerEngine\Update\OperationFiles::write($this->path,$encoded.PHP_EOL,$mode);
    }
}
