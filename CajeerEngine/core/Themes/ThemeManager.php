<?php

declare(strict_types=1);

namespace Cajeer\Themes;

use Cajeer\Config\ConfigRepository;
use Cajeer\Database\DatabaseManager;
use Throwable;

final class ThemeManager
{
    private ?ActiveTheme $active = null;
    private ?array $config = null;

    public function __construct(
        private readonly string $basePath,
        private readonly ?DatabaseManager $database = null,
        private readonly ?ConfigRepository $appConfig = null,
    ) {}

    public function active(?string $preview = null): ActiveTheme
    {
        if ($preview !== null && $preview !== '') {
            return $this->theme($preview);
        }
        if ($this->active instanceof ActiveTheme) {
            return $this->active;
        }
        $slug = $this->setting('active_theme', $this->appConfig?->get('themes.active', 'cajeer-official') ?? 'cajeer-official');
        return $this->active = $this->theme((string) $slug);
    }

    public function theme(string $slug): ActiveTheme
    {
        $slug = $this->sanitizeSlug($slug);
        $path = $this->basePath . '/themes/' . $slug;
        $manifestFile = $path . '/extension.json';
        if (!is_dir($path) || !is_file($manifestFile)) {
            $slug = 'cajeer-official';
            $path = $this->basePath . '/themes/' . $slug;
            $manifestFile = $path . '/extension.json';
        }
        $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : [];
        return new ActiveTheme($slug, $path, is_array($manifest) ? $manifest : []);
    }

    /** @return array<string,ActiveTheme> */
    public function all(): array
    {
        $themes = [];
        foreach (glob($this->basePath . '/themes/*/extension.json') ?: [] as $manifestFile) {
            $slug = basename(dirname($manifestFile));
            $themes[$slug] = $this->theme($slug);
        }
        ksort($themes);
        return $themes;
    }

    public function activate(string $slug): void
    {
        $theme = $this->theme($slug);
        $required = ['layout', 'home'];
        foreach ($required as $view) {
            if (!is_file($theme->path . '/views/' . $view . '.php')) {
                throw new \RuntimeException('Тема не содержит обязательный шаблон: ' . $view);
            }
        }
        $this->setSetting('active_theme', $theme->slug);
        $this->active = $theme;
        $this->config = null;
    }

    public function viewPath(string $view, ?string $preview = null): ?string
    {
        $theme = $this->active($preview);
        $file = $theme->path . '/views/' . str_replace('.', '/', $view) . '.php';
        return is_file($file) ? $file : null;
    }

    public function assetUrl(string $path, ?string $theme = null, bool $version = true): string
    {
        $themeSlug = $this->sanitizeSlug($theme ?: $this->active()->slug);
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $url = '/themes/' . $themeSlug . '/assets/' . $path;
        $file = $this->basePath . '/public' . $url;
        if ($version && is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }

    /** @return array<string,mixed> */
    public function config(?string $preview = null): array
    {
        if ($this->config !== null && $preview === null) {
            return $this->config;
        }
        $theme = $this->active($preview);
        $configFile = $theme->path . '/theme.php';
        $config = is_file($configFile) ? require $configFile : [];
        if (!is_array($config)) {
            $config = [];
        }
        $config = $this->overlaySettings($config);
        if ($preview === null) {
            $this->config = $config;
        }
        return $config;
    }

    public function pageMeta(string $page, ?array $project = null, ?string $preview = null): array
    {
        $config = $this->config($preview);
        if ($project !== null) {
            return [
                'title' => $project['meta_title'] ?? $project['name'] ?? $config['site']['name'] ?? 'Cajeer',
                'description' => $project['meta_description'] ?? $project['description'] ?? $config['site']['description'] ?? '',
                'updated_at' => $project['updated_at'] ?? null,
            ];
        }
        return $config['meta'][$page] ?? $config['meta']['404'] ?? [
            'title' => $config['site']['name'] ?? 'Cajeer',
            'description' => $config['site']['description'] ?? '',
        ];
    }

    private function sanitizeSlug(string $slug): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $slug), '-') ?: 'cajeer-official';
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        if (!$this->database) {
            return $default;
        }
        try {
            $stmt = $this->database->connection()->prepare('SELECT setting_value FROM cajeer_settings WHERE setting_key = :key LIMIT 1');
            $stmt->execute(['key' => $key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : $value;
        } catch (Throwable) {
            return $default;
        }
    }

    private function setSetting(string $key, string $value): void
    {
        if (!$this->database) {
            return;
        }
        try {
            $pdo = $this->database->connection();
            if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $stmt = $pdo->prepare('INSERT INTO cajeer_settings (setting_key, setting_value, autoload) VALUES (:key, :value, 1) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP');
            } else {
                $stmt = $pdo->prepare('INSERT INTO cajeer_settings (setting_key, setting_value, autoload) VALUES (:key, :value, 1) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
            }
            $stmt->execute(['key' => $key, 'value' => $value]);
        } catch (Throwable) {
            // Activation still works in file-only/smoke mode; installed environments persist this setting.
        }
    }

    private function overlaySettings(array $config): array
    {
        if (!$this->database) {
            return $config;
        }
        try {
            $rows = $this->database->connection()->query("SELECT setting_key, setting_value FROM cajeer_settings WHERE setting_key LIKE 'site.%' OR setting_key LIKE 'support.%' OR setting_key LIKE 'brand.%' OR setting_key LIKE 'seo.%'")->fetchAll() ?: [];
            foreach ($rows as $row) {
                $key = (string) $row['setting_key'];
                $value = (string) $row['setting_value'];
                [$section, $name] = array_pad(explode('.', $key, 2), 2, null);
                if ($section && $name) {
                    $config[$section][$name] = $value;
                }
            }
        } catch (Throwable) {
            return $config;
        }
        return $config;
    }
}
