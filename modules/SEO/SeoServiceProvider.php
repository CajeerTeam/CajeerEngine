<?php

declare(strict_types=1);

namespace Cajeer\Modules\SEO;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class SeoServiceProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('admin.menu', static function (array $payload): array {
            $payload['items'][] = ['title' => 'SEO', 'url' => '/admin/settings/seo'];
            return $payload;
        });
    }
}
