<?php

declare(strict_types=1);

use Cajeer\Compatibility\WordPress\WordPressRuntime;

if (!function_exists('cajeer_wp_runtime')) {
    function cajeer_wp_runtime(): WordPressRuntime { static $runtime = null; return $runtime ??= new WordPressRuntime(); }
}
if (!function_exists('add_action')) { function add_action(string $hook, callable $callback, int $priority = 10): void { cajeer_wp_runtime()->addAction($hook, $callback, $priority); } }
if (!function_exists('do_action')) { function do_action(string $hook, mixed ...$args): void { cajeer_wp_runtime()->doAction($hook, $args); } }
if (!function_exists('remove_action')) { function remove_action(string $hook, callable $callback, int $priority = 10): void { cajeer_wp_runtime()->removeAction($hook, $callback, $priority); } }
if (!function_exists('has_action')) { function has_action(string $hook): bool { return cajeer_wp_runtime()->hasAction($hook); } }
if (!function_exists('add_filter')) { function add_filter(string $hook, callable $callback, int $priority = 10): void { cajeer_wp_runtime()->addFilter($hook, $callback, $priority); } }
if (!function_exists('apply_filters')) { function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return cajeer_wp_runtime()->applyFilters($hook, $value, $args); } }
if (!function_exists('remove_filter')) { function remove_filter(string $hook, callable $callback, int $priority = 10): void { cajeer_wp_runtime()->removeFilter($hook, $callback, $priority); } }
if (!function_exists('has_filter')) { function has_filter(string $hook): bool { return cajeer_wp_runtime()->hasFilter($hook); } }
if (!function_exists('add_shortcode')) { function add_shortcode(string $tag, callable $callback): void { cajeer_wp_runtime()->addShortcode($tag, $callback); } }
if (!function_exists('do_shortcode')) { function do_shortcode(string $content): string { return cajeer_wp_runtime()->doShortcode($content); } }
if (!function_exists('esc_html')) { function esc_html(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url(mixed $value): string { return filter_var((string) $value, FILTER_SANITIZE_URL); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); } }
if (!function_exists('wp_parse_args')) { function wp_parse_args(array|string|null $args, array $defaults = []): array { return is_array($args) ? array_merge($defaults, $args) : $defaults; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post(mixed $value): string { return strip_tags((string) $value, '<p><br><a><strong><em><ul><ol><li><blockquote><code><pre><h1><h2><h3><h4><h5><h6><img>'); } }
if (!function_exists('get_option')) { function get_option(string $name, mixed $default = false): mixed { return cajeer_wp_runtime()->getOption($name, $default); } }
if (!function_exists('update_option')) { function update_option(string $name, mixed $value): bool { cajeer_wp_runtime()->updateOption($name, $value); return true; } }
if (!function_exists('delete_option')) { function delete_option(string $name): bool { cajeer_wp_runtime()->deleteOption($name); return true; } }
if (!function_exists('home_url')) { function home_url(string $path = ''): string { return rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/' . ltrim($path, '/'); } }
if (!function_exists('site_url')) { function site_url(string $path = ''): string { return home_url($path); } }
if (!function_exists('admin_url')) { function admin_url(string $path = ''): string { return home_url('admin/' . ltrim($path, '/')); } }
if (!function_exists('plugins_url')) { function plugins_url(string $path = '', string $plugin = ''): string { return home_url('plugins/' . ltrim($path, '/')); } }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url(string $file): string { return plugins_url('', $file); } }
if (!function_exists('get_permalink')) { function get_permalink(mixed $post = null): string { return home_url('news/' . (is_array($post) ? ($post['slug'] ?? '') : (string) $post)); } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $in_footer = false): void { cajeer_wp_runtime()->enqueueScript($handle, $src, $deps, $ver, $in_footer); } }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void { cajeer_wp_runtime()->enqueueStyle($handle, $src, $deps, $ver, $media); } }
if (!function_exists('wp_head')) { function wp_head(): void { echo cajeer_wp_runtime()->headHtml(); } }
if (!function_exists('wp_footer')) { function wp_footer(): void { echo cajeer_wp_runtime()->footerHtml(); } }
if (!function_exists('get_header')) { function get_header(): void {} }
if (!function_exists('get_footer')) { function get_footer(): void {} }
