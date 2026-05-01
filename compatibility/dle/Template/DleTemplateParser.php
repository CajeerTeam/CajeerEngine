<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\Dle\Template;

final class DleTemplateParser
{
    private array $supportedTags = ['title', 'short-story', 'full-story', 'date', 'category', 'link', 'author', 'navigation', 'pages', 'login', 'speedbar'];

    /** @param array<string,mixed> $data */
    public function renderString(string $template, array $data): string
    {
        $html = $this->renderConditionals($template, $data);
        $html = preg_replace_callback('/\{include\s+file=["\']([^"\']+)["\']\}/i', static fn (array $m): string => '<!-- include disabled: ' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . ' -->', $html) ?? $html;
        $html = preg_replace_callback('/\{custom\s+([^}]+)\}/i', static fn (array $m): string => '<!-- custom disabled: ' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . ' -->', $html) ?? $html;

        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($html, $replacements);
    }

    /** @return array<int,string> */
    public function detectTags(string $template): array
    {
        preg_match_all('/\{([a-zA-Z0-9_-]+)(?:\s+[^}]*)?\}/', $template, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }

    public function compatibilityReport(string $template): array
    {
        $tags = [];
        foreach ($this->detectTags($template) as $tag) {
            $tags[] = [
                'tag' => $tag,
                'status' => in_array($tag, $this->supportedTags, true) ? 'supported' : 'unsupported',
            ];
        }
        preg_match_all('/\[(aviable|not-aviable|group|not-group)=([^\]]+)\]/', $template, $conditions, PREG_SET_ORDER);
        foreach ($conditions as $condition) {
            $tags[] = ['tag' => $condition[1] . '=' . $condition[2], 'status' => 'partially_supported'];
        }
        preg_match_all('/\{(include|custom)\s+[^}]+\}/', $template, $dangerous, PREG_SET_ORDER);
        foreach ($dangerous as $tag) {
            $tags[] = ['tag' => $tag[1], 'status' => 'dangerous'];
        }
        return $tags;
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
        foreach ($rules as $regex => $callback) {
            $html = preg_replace_callback($regex, $callback, $html) ?? $html;
        }
        return $html;
    }
}
