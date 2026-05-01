<?php

declare(strict_types=1);

namespace Cajeer\Modules\Themes;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class ThemeServiceProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('admin.menu', static function (array $payload): array {
            $payload['items'][] = ['title' => 'Темы', 'url' => '/admin/themes'];
            return $payload;
        });
    }
}
