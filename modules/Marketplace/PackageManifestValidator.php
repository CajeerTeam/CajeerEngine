<?php

declare(strict_types=1);

namespace Cajeer\Modules\Marketplace;

use Cajeer\Extensions\ExtensionManifestValidator;

final class PackageManifestValidator
{
    public function validate(array $manifest): array
    {
        $errors = (new ExtensionManifestValidator())->validate($manifest);
        if (isset($manifest['engine']) && !is_string($manifest['engine'])) {
            $errors[] = 'engine должен быть строкой semver constraint.';
        }
        return $errors;
    }
}
