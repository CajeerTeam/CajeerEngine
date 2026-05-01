<?php

declare(strict_types=1);

namespace Cajeer\Container;

use RuntimeException;

final class Container
{
    /** @var array<string,mixed> */
    private array $bindings = [];

    public function set(string $id, mixed $value): void
    {
        $this->bindings[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->bindings);
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Сервис не зарегистрирован: {$id}");
        }

        $value = $this->bindings[$id];
        if ($value instanceof \Closure) {
            $value = $value($this);
            $this->bindings[$id] = $value;
        }

        return $value;
    }
}
