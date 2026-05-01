<?php

declare(strict_types=1);

namespace Cajeer\Modules\Admin;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class AdminServiceProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('admin.menu', static function (array $payload): array {
            $payload['items'][] = ['title' => 'Панель', 'url' => '/admin'];
            $payload['items'][] = ['title' => 'Контент', 'url' => '/admin/content'];
            $payload['items'][] = ['title' => 'Marketplace', 'url' => '/admin/marketplace'];
            $payload['items'][] = ['title' => 'Темы', 'url' => '/admin/themes'];
            $payload['items'][] = ['title' => 'Совместимость', 'url' => '/admin/compatibility'];
            return $payload;
        });
    }
}
