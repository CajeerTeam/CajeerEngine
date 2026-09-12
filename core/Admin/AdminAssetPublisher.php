<?php

declare(strict_types=1);

namespace CajeerEngine\Admin;

final readonly class AdminAssetPublisher
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function manifest(bool $write = false): array
    {
        $version = $this->version();
        $base = $this->rootPath . '/public/admin';
        $assets = [
            'index' => 'index.html',
            'script' => 'assets/admin-app.js',
            'style' => 'assets/admin-app.css',
            'legacy_script' => 'assets/admin-preview.js',
            'legacy_style' => 'assets/admin-preview.css',
        ];

        $files = [];
        foreach ($assets as $name => $relative) {
            $path = $base . '/' . $relative;
            $files[$name] = [
                'path' => '/admin/' . $relative,
                'file' => $relative,
                'exists' => is_file($path),
                'size' => is_file($path) ? filesize($path) : 0,
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
            ];
        }

        $manifest = [
            'name' => 'CajeerEngine Admin UI',
            'version' => $version,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'mode' => 'production-static-spa',
            'base_path' => '/admin/',
            'api_base' => '/api/v1',
            'source' => [
                'nuxt' => is_file($this->rootPath . '/admin/nuxt.config.ts'),
                'static_source' => is_dir($this->rootPath . '/admin/static'),
                'pages' => $this->countFiles($this->rootPath . '/admin/pages', 'vue'),
                'components' => $this->countFiles($this->rootPath . '/admin/components', 'vue'),
                'composables' => $this->countFiles($this->rootPath . '/admin/composables', 'ts'),
            ],
            'quality' => [
                'production_assets' => $this->productionQuality($files, $base),
                'min_app_js_bytes' => 18000,
                'min_app_css_bytes' => 3500,
            ],
            'entrypoints' => [
                'html' => '/admin/index.html',
                'script' => '/admin/assets/admin-app.js',
                'style' => '/admin/assets/admin-app.css',
            ],
            'files' => $files,
            'ready' => $this->ready($files) && $this->productionQuality($files, $base),
        ];

        if ($write) {
            $manifestPath = $base . '/assets/admin-manifest.json';
            if (!is_dir(dirname($manifestPath))) {
                mkdir(dirname($manifestPath), 0775, true);
            }
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }

        return $manifest;
    }

    /** @param array<string, array<string, mixed>> $files */
    private function productionQuality(array $files, string $base): bool
    {
        $index = $files['index'] ?? [];
        $script = $files['script'] ?? [];
        $style = $files['style'] ?? [];
        $scriptPath = $base . '/assets/admin-app.js';
        $scriptBody = is_file($scriptPath) ? (string) file_get_contents($scriptPath) : '';

        return (bool) (($index['exists'] ?? false)
            && (int) ($index['size'] ?? 0) >= 350
            && ($script['exists'] ?? false)
            && (int) ($script['size'] ?? 0) >= 18000
            && ($style['exists'] ?? false)
            && (int) ($style['size'] ?? 0) >= 3500
            && str_contains($scriptBody, 'Admin UI 1.1.1')
            && !str_contains($scriptBody, 'fallback assets are ready'));
    }

    /** @param array<string, array<string, mixed>> $files */
    private function ready(array $files): bool
    {
        foreach ($files as $file) {
            if (($file['exists'] ?? false) !== true || (int) ($file['size'] ?? 0) <= 0) {
                return false;
            }
        }
        return true;
    }

    private function countFiles(string $dir, string $extension): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === $extension) {
                $count++;
            }
        }
        return $count;
    }

    private function version(): string
    {
        $path = $this->rootPath . '/VERSION';
        if (is_file($path)) {
            $version = trim((string) file_get_contents($path));
            if ($version !== '') {
                return $version;
            }
        }
        return '1.1.1';
    }
}
