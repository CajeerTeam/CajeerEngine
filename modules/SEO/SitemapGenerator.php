<?php

declare(strict_types=1);

namespace Cajeer\Modules\SEO;

final class SitemapGenerator
{
    public function xml(array $urls): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1) . '</loc>';
            if (!empty($url['lastmod'])) $xml .= '<lastmod>' . htmlspecialchars(substr((string) $url['lastmod'], 0, 10), ENT_XML1) . '</lastmod>';
            $xml .= "</url>\n";
        }
        return $xml . '</urlset>';
    }
}
