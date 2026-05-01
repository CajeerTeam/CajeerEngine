<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\WordPress\Scanner;

final class PluginScanner
{
    public function scan(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException("WordPress-плагин не найден: {$file}");
        }

        $code = file_get_contents($file) ?: '';
        $functions = ['add_action', 'add_filter', 'add_shortcode', 'get_option', 'update_option', 'wp_enqueue_script', 'wp_enqueue_style'];
        $used = [];
        foreach ($functions as $function) {
            if (preg_match('/\b' . preg_quote($function, '/') . '\s*\(/', $code)) {
                $used[] = $function;
            }
        }

        return [
            'file' => $file,
            'used_compat_functions' => $used,
            'risk' => $this->risk($code),
        ];
    }

    private function risk(string $code): string
    {
        if (str_contains($code, '$wpdb') || str_contains($code, 'mysqli_') || str_contains($code, 'mysql_')) {
            return 'high';
        }
        if (str_contains($code, 'wp_remote_') || str_contains($code, 'curl_')) {
            return 'medium';
        }
        return 'low';
    }
}
