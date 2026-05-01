<?php

declare(strict_types=1);

namespace Cajeer\Modules\SEO;

final class OpenGraphRenderer
{
    public function render(array $meta): string
    {
        $html = '';
        foreach ($meta as $property => $content) {
            $html .= '<meta property="' . htmlspecialchars((string) $property, ENT_QUOTES, 'UTF-8') . '" content="' . htmlspecialchars((string) $content, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        return $html;
    }
}
