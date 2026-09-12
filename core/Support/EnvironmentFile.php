<?php

declare(strict_types=1);

namespace CajeerEngine\Support;

final class EnvironmentFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function ensureFromExample(string $examplePath): bool
    {
        if ($this->exists()) {
            return false;
        }

        if (!is_file($examplePath)) {
            throw new \RuntimeException('Файл .env.example не найден.');
        }

        if (!copy($examplePath, $this->path)) {
            throw new \RuntimeException('Не удалось создать .env из .env.example.');
        }

        return true;
    }

    /** @param array<string, string> $values @return array<string, array{old:string|null,new:string}> */
    public function setMany(array $values): array
    {
        if (!$this->exists()) {
            $contents = '';
        } else {
            $contents = (string) file_get_contents($this->path);
        }

        $changes = [];
        foreach ($values as $key => $value) {
            if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                throw new \InvalidArgumentException('Некорректное имя ENV-переменной: ' . $key);
            }
            $old = $this->get($key);
            if ($old === $value) {
                continue;
            }
            if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $contents)) {
                $contents = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $key . '=' . $value, $contents, 1);
            } else {
                $contents = rtrim($contents) . PHP_EOL . $key . '=' . $value . PHP_EOL;
            }
            $changes[$key] = ['old' => $old, 'new' => $value];
        }

        if ($changes !== []) {
            $directory = dirname($this->path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Не удалось создать директорию для .env: ' . $directory);
            }
            file_put_contents($this->path, rtrim($contents) . PHP_EOL, LOCK_EX);
        }

        return $changes;
    }

    public function get(string $key): ?string
    {
        if (!$this->exists()) {
            return null;
        }
        $contents = (string) file_get_contents($this->path);
        if (!preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $match)) {
            return null;
        }
        return trim((string) $match[1]);
    }

    public function ensureAppKey(): bool
    {
        if (!$this->exists()) {
            throw new \RuntimeException('Файл .env не найден.');
        }

        $contents = (string) file_get_contents($this->path);
        $key = 'base64:' . base64_encode(random_bytes(32));

        if (preg_match('/^APP_KEY=(.*)$/m', $contents, $match)) {
            $current = trim((string) $match[1]);
            if ($current !== '' && $current !== 'base64:CHANGE_ME' && $current !== 'CHANGE_ME') {
                return false;
            }

            $updated = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents, 1, $count);
            if ($count === 0 || !is_string($updated)) {
                return false;
            }
            file_put_contents($this->path, $updated);
            return true;
        }

        file_put_contents($this->path, rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL);
        return true;
    }
}
