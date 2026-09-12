<?php

declare(strict_types=1);

namespace CajeerEngine\Extension\Contracts;

use CajeerEngine\Extension\Runtime\ExtensionContext;
use CajeerEngine\Runtime\RuntimeEvent;
use CajeerEngine\Runtime\ServiceContainer;

interface ExtensionProviderInterface
{
    /** Register extension services in the runtime container. */
    public function register(ServiceContainer $container, ExtensionContext $context): void;

    /** Boot the extension after core services are registered. */
    public function boot(ExtensionContext $context): void;

    /** Run one-time install lifecycle logic. */
    public function install(ExtensionContext $context): void;

    /** Run one-time uninstall lifecycle logic before registry removal. */
    public function uninstall(ExtensionContext $context): void;

    /** Handle a runtime event subscribed through manifest events/hooks. */
    public function onEvent(RuntimeEvent $event, ExtensionContext $context): void;
}
