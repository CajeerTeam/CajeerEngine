<?php

declare(strict_types=1);

namespace Cajeer\View;

use RuntimeException;

final class ViewRenderer
{
    public function __construct(private readonly string $basePath) {}

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = []): string
    {
        $file = $this->basePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Шаблон не найден: {$view}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
