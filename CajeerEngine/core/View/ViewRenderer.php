<?php

declare(strict_types=1);

namespace Cajeer\View;

use Cajeer\Themes\ThemeManager;
use RuntimeException;

final class ViewRenderer
{
    public function __construct(private readonly string $basePath, private readonly ?ThemeManager $themes = null) {}

    /** @param array<string,mixed> $data */
    public function render(string $view, array $data = []): string
    {
        $file = null;
        if ($this->themes && $this->isPublicView($view) && (isset($data['config']) || str_starts_with($view, 'errors.'))) {
            $preview = isset($data['theme_preview']) ? (string) $data['theme_preview'] : null;
            $file = $this->themes->viewPath($view, $preview);
        }
        $file ??= $this->basePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Шаблон не найден: {$view}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    private function isPublicView(string $view): bool
    {
        foreach (['admin.', 'auth.', 'install.', 'marketplace.'] as $prefix) {
            if (str_starts_with($view, $prefix)) {
                return false;
            }
        }
        return true;
    }
}
