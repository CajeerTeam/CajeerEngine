<?php

declare(strict_types=1);

namespace Cajeer\Config;

final class ConfigRepository
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items = []) {}

    public static function load(string $configPath): self
    {
        $items = [];
        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $items[$key] = require $file;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->items;
    }
}
