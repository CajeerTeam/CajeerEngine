<?php

declare(strict_types=1);

function env_value(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}


if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('theme_manager')) {
    function theme_manager(): ?\Cajeer\Themes\ThemeManager
    {
        return $GLOBALS['cajeer_theme_manager'] ?? null;
    }
}

if (!function_exists('theme_asset')) {
    function theme_asset(string $path, ?string $theme = null): string
    {
        $manager = theme_manager();
        if ($manager) {
            return $manager->assetUrl($path, $theme);
        }
        return '/themes/' . ($theme ?: 'cajeer-official') . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('site_value')) {
    function site_value(array $config, string $key, mixed $default = null): mixed
    {
        return $config['site'][$key] ?? $config['brand'][$key] ?? $default;
    }
}

if (!function_exists('support_value')) {
    function support_value(array $config, string $key, mixed $default = null): mixed
    {
        return $config['support'][$key] ?? $default;
    }
}

if (!function_exists('brand_value')) {
    function brand_value(array $config, string $key, mixed $default = null): mixed
    {
        return $config['brand'][$key] ?? $config['site'][$key] ?? $default;
    }
}

if (!function_exists('project_status_label')) {
    function project_status_label(array $config, ?string $status): string
    {
        return $config['project_status_labels'][$status ?? ''] ?? 'Проект';
    }
}

if (!function_exists('site_domain')) {
    function site_domain(array $config): string
    {
        return rtrim((string) site_value($config, 'domain', ''), '/');
    }
}
