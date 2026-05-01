<?php

declare(strict_types=1);

namespace Cajeer\Events;

final class EventDispatcher
{
    /** @var array<string,array<int,callable>> */
    private array $listeners = [];

    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][$priority][] = $listener;
        krsort($this->listeners[$event]);
    }

    /** @param array<string,mixed> $payload */
    public function dispatch(string $event, array $payload = []): array
    {
        foreach ($this->listeners[$event] ?? [] as $listeners) {
            foreach ($listeners as $listener) {
                $payload = $listener($payload) ?? $payload;
            }
        }
        return $payload;
    }

    public function filter(string $event, mixed $value, array $context = []): mixed
    {
        $payload = ['value' => $value, 'context' => $context];
        $payload = $this->dispatch($event, $payload);
        return $payload['value'] ?? $value;
    }
}
