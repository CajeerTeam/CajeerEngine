<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class AuthServiceProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('admin.menu', static function (array $payload): array {
            $payload['items'][] = ['title' => 'Выход', 'url' => '/admin/logout'];
            return $payload;
        });
    }
}
