<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\Dle\Template;

final class DleTemplateParser
{
    private array $supportedTags = ['title', 'short-story', 'full-story', 'date', 'category', 'link', 'author', 'navigation', 'pages', 'login', 'speedbar'];

    public function __construct(private readonly ?string $themeRoot = null) {}

    /** @param array<string,mixed> $data */
    public function renderString(string $template, array $data): string
    {
        $html = $this->renderConditionals($template, $data);
        $html = $this->renderIncludes($html, $data);
        $html = $this->renderCustom($html, $data);

        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($html, $replacements);
    }

    public function detectTags(string $template): array
    {
        preg_match_all('/\{([a-zA-Z0-9_-]+)(?:\s+[^}]*)?\}/', $template, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    public function compatibilityReport(string $template): array
    {
        $tags = [];
        foreach ($this->detectTags($template) as $tag) {
            $status = in_array($tag, $this->supportedTags, true) ? 'supported' : 'unsupported';
            if (in_array($tag, ['include', 'custom'], true)) $status = 'dangerous';
            $tags[] = ['tag' => $tag, 'status' => $status];
        }
        preg_match_all('/\[(aviable|not-aviable|group|not-group)=([^\]]+)\]/', $template, $conditions, PREG_SET_ORDER);
        foreach ($conditions as $condition) {
            $tags[] = ['tag' => $condition[1] . '=' . $condition[2], 'status' => 'partially_supported'];
        }
        return $tags;
    }

    private function renderIncludes(string $html, array $data): string
    {
        return preg_replace_callback('/\{include\s+file=["\']([^"\']+)["\']\}/i', function (array $m) use ($data): string {
            if (!$this->themeRoot) return '<!-- include disabled -->';
            $file = $this->safePath($m[1]);
            if (!$file || !is_file($file)) return '<!-- include not found -->';
            return $this->renderString(file_get_contents($file) ?: '', $data);
        }, $html) ?? $html;
    }

    private function renderCustom(string $html, array $data): string
    {
        return preg_replace_callback('/\{custom\s+([^}]+)\}/i', static function (array $m) use ($data): string {
            $items = $data['_custom_items'] ?? [];
            if (!is_array($items) || $items === []) return '<!-- custom empty -->';
            return implode("\n", array_map('strval', $items));
        }, $html) ?? $html;
    }

    private function safePath(string $file): ?string
    {
        if (str_contains($file, '..') || str_starts_with($file, '/') || str_contains($file, '://')) return null;
        $root = realpath((string) $this->themeRoot);
        if (!$root) return null;
        $path = realpath($root . DIRECTORY_SEPARATOR . $file);
        if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
        return $path;
    }

    private function renderConditionals(string $html, array $data): string
    {
        $rules = [
            '/\[not-empty=([a-zA-Z0-9_-]+)\](.*?)\[\/not-empty\]/s' => static fn ($m) => !empty($data[$m[1]]) ? $m[2] : '',
            '/\[empty=([a-zA-Z0-9_-]+)\](.*?)\[\/empty\]/s' => static fn ($m) => empty($data[$m[1]]) ? $m[2] : '',
            '/\[aviable=([^\]]+)\](.*?)\[\/aviable\]/s' => static fn ($m) => in_array($data['_route'] ?? 'main', array_map('trim', explode(',', $m[1])), true) ? $m[2] : '',
            '/\[not-aviable=([^\]]+)\](.*?)\[\/not-aviable\]/s' => static fn ($m) => !in_array($data['_route'] ?? 'main', array_map('trim', explode(',', $m[1])), true) ? $m[2] : '',
            '/\[group=([^\]]+)\](.*?)\[\/group\]/s' => static fn ($m) => in_array((string) ($data['_group'] ?? '5'), array_map('trim', explode(',', $m[1])), true) ? $m[2] : '',
            '/\[not-group=([^\]]+)\](.*?)\[\/not-group\]/s' => static fn ($m) => !in_array((string) ($data['_group'] ?? '5'), array_map('trim', explode(',', $m[1])), true) ? $m[2] : '',
        ];
        foreach ($rules as $regex => $callback) $html = preg_replace_callback($regex, $callback, $html) ?? $html;
        return $html;
    }
}
