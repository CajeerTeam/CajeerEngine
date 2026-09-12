<?php

declare(strict_types=1);

namespace CajeerEngine\Queue;

final readonly class QueuedJob
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $name,
        public array $payload,
        public string $queue = 'default',
        public int $attempts = 0,
        public int $maxAttempts = 3,
        public ?string $availableAt = null,
        public ?string $reservedAt = null,
        public ?string $source = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'payload' => $this->payload,
            'queue' => $this->queue,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'available_at' => $this->availableAt,
            'reserved_at' => $this->reservedAt,
            'source' => $this->source,
        ];
    }
}
