<?php

declare(strict_types=1);

namespace CajeerEngine\Scheduler;

final class Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    public function everyMinute(string $name, callable $handler): void
    {
        $this->tasks[] = new ScheduledTask($name, '* * * * *', $handler);
    }

    /** @return list<ScheduledTask> */
    public function due(\DateTimeImmutable $now): array
    {
        return $this->tasks;
    }
}
