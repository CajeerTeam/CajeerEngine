<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\WordPress;

final class WordPressRuntime
{
    /** @var array<string,array<int,array<int,callable>>> */
    private array $actions = [];

    /** @var array<string,array<int,array<int,callable>>> */
    private array $filters = [];

    /** @var array<string,callable> */
    private array $shortcodes = [];

    /** @var array<string,mixed> */
    private array $options = [];

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][$priority][] = $callback;
        ksort($this->actions[$hook]);
    }

    public function doAction(string $hook, array $args = []): void
    {
        foreach ($this->actions[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
        ksort($this->filters[$hook]);
    }

    public function applyFilters(string $hook, mixed $value, array $args = []): mixed
    {
        foreach ($this->filters[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }
        return $value;
    }

    public function addShortcode(string $tag, callable $callback): void
    {
        $this->shortcodes[$tag] = $callback;
    }

    public function doShortcode(string $content): string
    {
        return preg_replace_callback('/\[([a-zA-Z0-9_-]+)([^\]]*)\]/', function (array $matches): string {
            $tag = $matches[1];
            if (!isset($this->shortcodes[$tag])) {
                return $matches[0];
            }
            return (string) $this->shortcodes[$tag]([], null, $tag);
        }, $content) ?? $content;
    }

    public function getOption(string $name, mixed $default = false): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function updateOption(string $name, mixed $value): void
    {
        $this->options[$name] = $value;
    }
}
