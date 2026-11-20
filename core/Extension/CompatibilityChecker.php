<?php

declare(strict_types=1);

namespace CajeerEngine\Extension;

final readonly class CompatibilityChecker
{
    public function __construct(private string $engineApiVersion)
    {
    }

    public function isCompatible(ExtensionManifest $manifest): bool
    {
        return VersionConstraint::matches($this->engineApiVersion, $manifest->engineConstraint);
    }

    public function assertCompatible(ExtensionManifest $manifest): void
    {
        if (!$this->isCompatible($manifest)) {
            throw new \RuntimeException(sprintf(
                'Extension %s requires Engine API %s; installed Engine API is %s.',
                $manifest->name,
                $manifest->engineConstraint,
                $this->engineApiVersion,
            ));
        }
    }
}
