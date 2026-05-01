<?php

declare(strict_types=1);

namespace Cajeer\Modules\SEO;

final class RobotsGenerator
{
    public function text(string $sitemapUrl): string
    {
        return "User-agent: *\nAllow: /\nSitemap: {$sitemapUrl}\n";
    }
}
