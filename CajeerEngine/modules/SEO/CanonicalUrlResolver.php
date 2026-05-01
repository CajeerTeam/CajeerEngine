<?php

declare(strict_types=1);

namespace Cajeer\Modules\SEO;

final class CanonicalUrlResolver
{
    public function resolve(string $domain, string $path): string
    {
        return rtrim($domain, '/') . '/' . trim($path, '/');
    }
}
