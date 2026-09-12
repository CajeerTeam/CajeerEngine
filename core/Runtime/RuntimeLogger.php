<?php

declare(strict_types=1);

namespace CajeerEngine\Runtime;

final readonly class RuntimeLogger
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function event(RuntimeEvent $event, array $context = []): void
    {
        $this->write('event', $event->name, array_merge($event->payload, $context));
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $path = $this->rootPath . '/storage/logs/runtime.jsonl';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $entry = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        try {
            file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Runtime logger must never break the product runtime.
        }
    }
}
