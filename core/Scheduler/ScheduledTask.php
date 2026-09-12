<?php

declare(strict_types=1);

namespace CajeerEngine\Scheduler;

final readonly class ScheduledTask
{
    public function __construct(
        public string $name,
        public string $expression,
        public mixed $handler,
    ) {
    }
}
