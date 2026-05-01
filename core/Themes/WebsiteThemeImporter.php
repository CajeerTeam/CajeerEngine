<?php

declare(strict_types=1);

namespace Cajeer\Themes;

final class WebsiteThemeImporter
{
    public function __construct(private readonly string $basePath) {}

    public function import(string $source, string $slug): array
    {
        $source = rtrim($source, '/');
        if (!is_dir($source)) {
            throw new \RuntimeException('Источник шаблона не найден: ' . $source);
        }
        $slug = trim((string) preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $slug), '-') ?: 'imported-theme';
        $target = $this->basePath . '/themes/' . $slug;
        if (!is_dir($target . '/views')) mkdir($target . '/views', 0775, true);
        if (is_dir($source . '/assets')) {
            $this->copyDir($source . '/assets', $target . '/assets');
            $this->copyDir($source . '/assets', $this->basePath . '/public/themes/' . $slug . '/assets');
        }
        foreach (glob($source . '/pages/*.php') ?: [] as $page) {
            copy($page, $target . '/views/' . basename($page));
        }
        file_put_contents($target . '/extension.json', json_encode([
            'name' => 'cajeer/' . $slug,
            'slug' => $slug,
            'type' => 'theme',
            'version' => '0.1.0',
            'entry' => 'theme.php',
            'description' => 'Импортированная тема из website template.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return ['theme' => $slug, 'target' => $target];
    }

    private function copyDir(string $from, string $to): void
    {
        if (is_dir($to)) $this->removeDir($to);
        mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $src = $from . '/' . $item;
            $dst = $to . '/' . $item;
            is_dir($src) ? $this->copyDir($src, $dst) : copy($src, $dst);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
