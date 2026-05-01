<?php

declare(strict_types=1);

namespace Cajeer\Modules\SEO;

final class SeoManager
{
    public function meta(string $title, string $description, string $canonical): array
    {
        return ['title' => $title, 'description' => $description, 'canonical' => $canonical];
    }
}
