<?php

declare(strict_types=1);

namespace CajeerEngine\ExtensionSdk;

interface Extension
{
    public function boot(ExtensionContext $context): void;
}
