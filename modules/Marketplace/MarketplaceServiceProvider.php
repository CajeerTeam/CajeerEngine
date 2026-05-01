<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace;

use Cajeer\Container\Container;
use Cajeer\Events\EventDispatcher;

final class MarketplaceServiceProvider
{
    public function register(Container $container, EventDispatcher $events, array $manifest): void
    {
        $events->listen('marketplace.package.scan', static fn (array $payload): array => $payload);
    }
}
