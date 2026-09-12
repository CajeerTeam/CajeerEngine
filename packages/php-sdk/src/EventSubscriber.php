<?php

declare(strict_types=1);

namespace CajeerEngine\ExtensionSdk;

interface EventSubscriber
{
    /** @param array<string, mixed> $payload */
    public function handle(string $event, array $payload, ExtensionContext $context): void;
}
