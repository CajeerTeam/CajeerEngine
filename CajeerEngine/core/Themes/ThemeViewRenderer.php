<?php

declare(strict_types=1);

namespace Cajeer\Themes;

final class ThemeViewRenderer
{
    public function __construct(private readonly ThemeManager $themes) {}

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = [], ?string $preview = null): string
    {
        $file = $this->themes->viewPath($view, $preview);
        if (!$file) {
            throw new \RuntimeException('Шаблон активной темы не найден: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
