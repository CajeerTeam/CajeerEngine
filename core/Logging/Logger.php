<?php

declare(strict_types=1);

namespace Cajeer\Logging;

final class Logger
{
    public function __construct(private readonly string $file) {}

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'time' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $this->normalizeContext($context),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;

        file_put_contents($this->file, $line, FILE_APPEND);
    }

    private function normalizeContext(array $context): array
    {
        return array_map(static function (mixed $value): mixed {
            if ($value instanceof \Throwable) {
                return [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            }
            return $value;
        }, $context);
    }
}
