<?php

declare(strict_types=1);

namespace CajeerEngine\Extension\Contracts;

use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Runtime\RuntimeEvent;
use CajeerEngine\Runtime\ServiceContainer;

abstract class AbstractExtensionProvider implements ExtensionProviderInterface
{
    public function register(ServiceContainer $container, ExtensionContext $context): void
    {
    }

    public function boot(ExtensionContext $context): void
    {
    }

    public function install(ExtensionContext $context): void
    {
    }

    public function uninstall(ExtensionContext $context): void
    {
    }

    public function onEvent(RuntimeEvent $event, ExtensionContext $context): void
    {
        $method = $context->eventMethodName($event->name);
        if (method_exists($this, $method)) {
            $this->{$method}($event, $context);
        }
    }
}
