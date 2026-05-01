<?php

declare(strict_types=1);

namespace Cajeer\Modules\Content;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class ContentServiceProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('content.before_render', static function (array $payload): array {
            $payload['value'] = $payload['value'] ?? '';
            return $payload;
        });
    }
}
