<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\WordPress;

use Cajeer\Database\DatabaseManager;
use Throwable;

final class WordPressRuntime
{
    private array $actions = [];
    private array $filters = [];
    private array $shortcodes = [];
    private array $options = [];
    private array $scripts = [];
    private array $styles = [];
    private ?DatabaseManager $database = null;

    public function setDatabase(DatabaseManager $database): void { $this->database = $database; }
    public function addAction(string $hook, callable $callback, int $priority = 10): void { $this->actions[$hook][$priority][] = $callback; ksort($this->actions[$hook]); }
    public function removeAction(string $hook, callable $callback, int $priority = 10): void { $this->remove($this->actions, $hook, $callback, $priority); }
    public function hasAction(string $hook): bool { return !empty($this->actions[$hook]); }
    public function doAction(string $hook, array $args = []): void { foreach ($this->actions[$hook] ?? [] as $callbacks) foreach ($callbacks as $callback) $callback(...$args); }
    public function addFilter(string $hook, callable $callback, int $priority = 10): void { $this->filters[$hook][$priority][] = $callback; ksort($this->filters[$hook]); }
    public function removeFilter(string $hook, callable $callback, int $priority = 10): void { $this->remove($this->filters, $hook, $callback, $priority); }
    public function hasFilter(string $hook): bool { return !empty($this->filters[$hook]); }
    public function applyFilters(string $hook, mixed $value, array $args = []): mixed { foreach ($this->filters[$hook] ?? [] as $callbacks) foreach ($callbacks as $callback) $value = $callback($value, ...$args); return $value; }
    public function addShortcode(string $tag, callable $callback): void { $this->shortcodes[$tag] = $callback; }
    public function doShortcode(string $content): string
    {
        return preg_replace_callback('/\[([a-zA-Z0-9_-]+)([^\]]*)\]/', function (array $matches): string {
            $tag = $matches[1];
            if (!isset($this->shortcodes[$tag])) return $matches[0];
            return (string) $this->shortcodes[$tag]([], null, $tag);
        }, $content) ?? $content;
    }

    public function getOption(string $name, mixed $default = false): mixed
    {
        if ($this->database) {
            try {
                $stmt = $this->database->connection()->prepare('SELECT setting_value FROM cajeer_settings WHERE setting_key = :key LIMIT 1');
                $stmt->execute(['key' => 'wp.' . $name]);
                $value = $stmt->fetchColumn();
                return $value === false ? $default : $value;
            } catch (Throwable) {}
        }
        return $this->options[$name] ?? $default;
    }

    public function updateOption(string $name, mixed $value): void
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->database) {
            try {
                $pdo = $this->database->connection();
                $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
                if ($driver === 'pgsql') {
                    $stmt = $pdo->prepare('INSERT INTO cajeer_settings (setting_key, setting_value, autoload) VALUES (:key, :value, 1) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP');
                } else {
                    $stmt = $pdo->prepare('INSERT INTO cajeer_settings (setting_key, setting_value, autoload) VALUES (:key, :value, 1) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
                }
                $stmt->execute(['key' => 'wp.' . $name, 'value' => $value]);
                return;
            } catch (Throwable) {}
        }
        $this->options[$name] = $value;
    }

    public function deleteOption(string $name): void
    {
        if ($this->database) {
            try {
                $stmt = $this->database->connection()->prepare('DELETE FROM cajeer_settings WHERE setting_key = :key');
                $stmt->execute(['key' => 'wp.' . $name]);
                return;
            } catch (Throwable) {}
        }
        unset($this->options[$name]);
    }

    public function enqueueScript(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $inFooter = false): void { $this->scripts[$handle] = compact('handle', 'src', 'deps', 'ver', 'inFooter'); }
    public function enqueueStyle(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void { $this->styles[$handle] = compact('handle', 'src', 'deps', 'ver', 'media'); }
    public function headHtml(): string { $this->doAction('wp_head'); return $this->renderStyles(); }
    public function footerHtml(): string { $this->doAction('wp_footer'); return $this->renderScripts(true); }

    private function renderStyles(): string
    {
        $html = '';
        foreach ($this->styles as $style) {
            if ($style['src'] === '') continue;
            $src = htmlspecialchars((string) $style['src'], ENT_QUOTES, 'UTF-8');
            $media = htmlspecialchars((string) $style['media'], ENT_QUOTES, 'UTF-8');
            $html .= "<link rel=\"stylesheet\" href=\"{$src}\" media=\"{$media}\">\n";
        }
        return $html;
    }

    private function renderScripts(bool $footer): string
    {
        $html = '';
        foreach ($this->scripts as $script) {
            if ((bool) $script['inFooter'] !== $footer || $script['src'] === '') continue;
            $src = htmlspecialchars((string) $script['src'], ENT_QUOTES, 'UTF-8');
            $html .= "<script src=\"{$src}\"></script>\n";
        }
        return $html;
    }

    private function remove(array &$bucket, string $hook, callable $callback, int $priority): void
    {
        foreach ($bucket[$hook][$priority] ?? [] as $i => $registered) {
            if ($registered === $callback) unset($bucket[$hook][$priority][$i]);
        }
    }
}
