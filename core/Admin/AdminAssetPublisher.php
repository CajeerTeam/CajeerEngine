<?php

declare(strict_types=1);
namespace CajeerEngine\Admin;

final readonly class AdminAssetPublisher
{
    public function __construct(private string $rootPath) {}

    public function manifest(bool $write = false): array
    {
        $version = trim((string) file_get_contents($this->rootPath . '/VERSION'));
        $base = $this->rootPath . '/public/admin';
        $files = [];
        $ready = true;
        foreach (['index.html', 'assets/admin-app.js', 'assets/admin-app.css', 'assets/admin-preview.js', 'assets/admin-preview.css'] as $relative) {
            $path = $base . '/' . $relative;
            $exists = is_file($path) && !is_link($path);
            $size = $exists ? filesize($path) : 0;
            $hash = $exists ? hash_file('sha256', $path) : null;
            $files[$relative] = ['path' => '/admin/' . $relative, 'exists' => $exists, 'size' => $size, 'sha256' => $hash];
            $source = $this->rootPath . '/admin/static/' . $relative;
            $ready = $ready && $exists && $size > 0 && (!is_file($source) || hash_file('sha256', $source) === $hash);
        }
        $index = $files['index.html']['exists'] ? file_get_contents($base . '/index.html') : '';
        $ready = $ready && str_contains($index, '/admin/assets/admin-app.js') && str_contains($index, '/admin/assets/admin-app.css')
            && $files['assets/admin-app.js']['size'] >= 18000 && $files['assets/admin-app.css']['size'] >= 3500;
        $manifest = ['name' => 'CajeerEngine Admin UI', 'version' => $version, 'mode' => 'production-static-spa', 'base_path' => '/admin/', 'api_base' => '/api/v1', 'files' => $files, 'quality' => ['production_assets' => $ready], 'ready' => $ready];
        $path = $base . '/assets/admin-manifest.json';
        if ($write && $ready && is_dir($this->rootPath . '/admin/static')) {
            if (file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write admin manifest');
            }
        } else {
            $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $ready = $ready && is_array($stored) && ($stored['version'] ?? null) === $version && ($stored['mode'] ?? null) === 'production-static-spa' && ($stored['ready'] ?? false) === true && ($stored['files'] ?? null) === $files;
            $manifest['ready'] = $manifest['quality']['production_assets'] = $ready;
        }
        return $manifest;
    }
}
