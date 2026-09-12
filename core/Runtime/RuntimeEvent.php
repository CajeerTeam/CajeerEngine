<?php

declare(strict_types=1);

namespace CajeerEngine\Runtime;

final readonly class RuntimeEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $name,
        public array $payload = [],
        public ?\DateTimeImmutable $occurredAt = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'payload' => $this->payload,
            'occurred_at' => ($this->occurredAt ?? new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
