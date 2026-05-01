<?php

declare(strict_types=1);

namespace Cajeer\App\ExamplePlugin;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class ExamplePluginProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('admin.menu', static function (array $payload): array {
            $payload['items'][] = ['title' => 'Example Plugin', 'url' => '/admin/example-plugin'];
            return $payload;
        });
    }
}
