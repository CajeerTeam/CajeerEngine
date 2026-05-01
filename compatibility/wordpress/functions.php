<?php

declare(strict_types=1);

use Cajeer\Compatibility\WordPress\WordPressRuntime;

if (!function_exists('cajeer_wp_runtime')) {
    function cajeer_wp_runtime(): WordPressRuntime
    {
        static $runtime = null;
        if (!$runtime) {
            $runtime = new WordPressRuntime();
        }
        return $runtime;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        cajeer_wp_runtime()->addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        cajeer_wp_runtime()->doAction($hook, $args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        cajeer_wp_runtime()->addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return cajeer_wp_runtime()->applyFilters($hook, $value, $args);
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        cajeer_wp_runtime()->addShortcode($tag, $callback);
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content): string
    {
        return cajeer_wp_runtime()->doShortcode($content);
    }
}

if (!function_exists('esc_html')) {
    function esc_html(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return cajeer_wp_runtime()->getOption($name, $default);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        cajeer_wp_runtime()->updateOption($name, $value);
        return true;
    }
}
