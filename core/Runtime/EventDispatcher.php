<?php

declare(strict_types=1);

namespace CajeerEngine\Runtime;

final class EventDispatcher
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var list<array<string, mixed>> */
    private array $dispatched = [];

    public function __construct(private readonly ?RuntimeLogger $logger = null)
    {
    }

    public function listen(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    public function dispatch(RuntimeEvent $event): RuntimeEvent
    {
        $this->dispatched[] = $event->toArray();
        $this->logger?->event($event);

        foreach ($this->listeners[$event->name] ?? [] as $listener) {
            $listener($event);
        }

        foreach ($this->listeners['*'] ?? [] as $listener) {
            $listener($event);
        }

        return $event;
    }

    /** @return list<array<string, mixed>> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }

    /** @return array<string, int> */
    public function listenerCounts(): array
    {
        $result = [];
        foreach ($this->listeners as $name => $listeners) {
            $result[$name] = count($listeners);
        }
        ksort($result);
        return $result;
    }
}
