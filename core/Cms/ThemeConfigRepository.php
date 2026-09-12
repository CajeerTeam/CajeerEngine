<?php

declare(strict_types=1);

namespace CajeerEngine\Cms;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use PDO;
use Throwable;

final class ThemeConfigRepository
{
    private JsonFile $file;
    private ?DatabaseManager $database = null;
    private ?bool $databaseReady = null;

    public function __construct(private readonly string $rootPath, ?DatabaseManager $database = null)
    {
        $this->database = $database;
        $this->file = new JsonFile($this->rootPath . '/storage/app/cms/theme-config.json');
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $config = $this->read();
        return $this->mergeDefaults($config);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function save(array $payload): array
    {
        $config = $this->normalize($payload + $this->get());
        if ($this->useDatabase()) {
            $this->saveDatabase($config);
        } else {
            $this->file->writeObject([
                'version' => 1,
                'updated_at' => $this->now(),
                'config' => $config,
            ]);
        }

        return $config;
    }

    /** @return array<string,mixed> */
    public function defaults(): array
    {
        return [
            'site_name' => 'CajeerEngine',
            'site_description' => 'Сайт работает на CajeerEngine.',
            'locale' => 'ru',
            'home_slug' => 'home',
            'primary_menu' => 'primary',
            'accent_color' => '#2563eb',
            'favicon_url' => '',
            'robots_index' => true,
            'robots_follow' => true,
            'sitemap_enabled' => true,
            'seo' => [
                'title_suffix' => '',
                'default_title' => 'CajeerEngine',
                'default_description' => 'Сайт работает на CajeerEngine.',
                'og_image' => '',
            ],
            'layout' => [
                'container_width' => '1040px',
                'show_powered_by' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if ($this->useDatabase()) {
            return $this->readDatabase();
        }

        $data = $this->file->readObject(['config' => []]);
        return is_array($data['config'] ?? null) ? $data['config'] : [];
    }

    /** @return array<string,mixed> */
    private function readDatabase(): array
    {
        $stmt = $this->pdo()->prepare('SELECT value FROM ce_cms_theme_settings WHERE key_name = :key LIMIT 1');
        $stmt->execute(['key' => 'default']);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $config */
    private function saveDatabase(array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = $this->nowForDatabase();
        $driver = $this->database()->driver();

        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO ce_cms_theme_settings (key_name, value, updated_at) VALUES (:key, :value, :updated_at) ON CONFLICT (key_name) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at';
        } elseif ($driver === 'mysql') {
            $sql = 'INSERT INTO ce_cms_theme_settings (key_name, value, updated_at) VALUES (:key, :value, :updated_at) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO ce_cms_theme_settings (key_name, value, updated_at) VALUES (:key, :value, :updated_at) ON CONFLICT(key_name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at';
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['key' => 'default', 'value' => $json, 'updated_at' => $now]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalize(array $input): array
    {
        $defaults = $this->defaults();
        $seo = is_array($input['seo'] ?? null) ? array_merge($defaults['seo'], $input['seo']) : $defaults['seo'];
        $layout = is_array($input['layout'] ?? null) ? array_merge($defaults['layout'], $input['layout']) : $defaults['layout'];

        return [
            'site_name' => $this->shortString($input['site_name'] ?? $defaults['site_name'], 120),
            'site_description' => $this->shortString($input['site_description'] ?? $defaults['site_description'], 280),
            'locale' => preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', (string) ($input['locale'] ?? '')) ? (string) $input['locale'] : 'ru',
            'home_slug' => $this->path((string) ($input['home_slug'] ?? $defaults['home_slug'])),
            'primary_menu' => $this->handle((string) ($input['primary_menu'] ?? $defaults['primary_menu']), 'primary'),
            'accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($input['accent_color'] ?? '')) ? strtolower((string) $input['accent_color']) : $defaults['accent_color'],
            'favicon_url' => $this->url((string) ($input['favicon_url'] ?? '')),
            'robots_index' => filter_var($input['robots_index'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'robots_follow' => filter_var($input['robots_follow'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'sitemap_enabled' => filter_var($input['sitemap_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'seo' => [
                'title_suffix' => $this->shortString($seo['title_suffix'] ?? '', 80),
                'default_title' => $this->shortString($seo['default_title'] ?? $defaults['seo']['default_title'], 120),
                'default_description' => $this->shortString($seo['default_description'] ?? $defaults['seo']['default_description'], 280),
                'og_image' => $this->url((string) ($seo['og_image'] ?? '')),
            ],
            'layout' => [
                'container_width' => preg_match('/^\d{2,4}(px|rem|%)$/', (string) ($layout['container_width'] ?? '')) ? (string) $layout['container_width'] : $defaults['layout']['container_width'],
                'show_powered_by' => filter_var($layout['show_powered_by'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function mergeDefaults(array $config): array
    {
        return $this->normalize($config + $this->defaults());
    }

    private function useDatabase(): bool
    {
        if ($this->databaseReady !== null) {
            return $this->databaseReady;
        }

        $mode = strtolower(trim((string) (getenv('CMS_STORAGE') ?: 'auto')));
        if ($mode === 'file') {
            return $this->databaseReady = false;
        }

        try {
            $this->databaseReady = $this->database()->tableExists('ce_cms_theme_settings');
        } catch (Throwable $e) {
            if ($mode === 'database') {
                throw new \RuntimeException('CMS_STORAGE=database, но CMS theme tables недоступны: ' . $e->getMessage(), 0, $e);
            }
            $this->databaseReady = false;
        }

        if ($mode === 'database' && $this->databaseReady !== true) {
            throw new \RuntimeException('CMS_STORAGE=database, но таблица ce_cms_theme_settings не найдена. Выполните миграции.');
        }

        return $this->databaseReady;
    }

    private function database(): DatabaseManager
    {
        if (!$this->database instanceof DatabaseManager) {
            $this->database = new DatabaseManager(new ConfigRepository($this->rootPath));
        }
        return $this->database;
    }

    private function pdo(): PDO
    {
        return $this->database()->connection();
    }

    private function shortString(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function handle(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^[a-z][a-z0-9_]{1,99}$/', $value) ? $value : $fallback;
    }

    private function path(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B/");
        if ($value === '') {
            return 'home';
        }
        $normalized = preg_replace('/[^a-zA-Z0-9_\-\/]/', '-', $value);
        return $normalized ?: 'home';
    }

    private function url(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '/') || filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return $value;
        }
        return '';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    private function nowForDatabase(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
