<?php

declare(strict_types=1);

namespace Cajeer\Compatibility\Dle\Template;

final class DleTemplateParser
{
    /** @param array<string,mixed> $data */
    public function renderString(string $template, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        $html = strtr($template, $replacements);

        $html = preg_replace_callback('/\[not-empty=([a-zA-Z0-9_-]+)\](.*?)\[\/not-empty\]/s', static function (array $m) use ($data): string {
            return !empty($data[$m[1]]) ? $m[2] : '';
        }, $html) ?? $html;

        $html = preg_replace_callback('/\[empty=([a-zA-Z0-9_-]+)\](.*?)\[\/empty\]/s', static function (array $m) use ($data): string {
            return empty($data[$m[1]]) ? $m[2] : '';
        }, $html) ?? $html;

        return $html;
    }

    /** @return array<int,string> */
    public function detectTags(string $template): array
    {
        preg_match_all('/\{([a-zA-Z0-9_-]+)\}/', $template, $matches);
        return array_values(array_unique($matches[1] ?? []));
    }
}
