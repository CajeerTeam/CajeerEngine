<?php

declare(strict_types=1);

namespace Cajeer\Extensions;

final class ExtensionManifestValidator
{
    private const DANGEROUS = ['db.raw_query', 'files.write', 'users.write', 'settings.write'];

    public function validate(array $manifest): array
    {
        $errors = [];
        foreach (['name', 'type', 'version'] as $required) {
            if (empty($manifest[$required]) || !is_string($manifest[$required])) {
                $errors[] = "Поле {$required} обязательно.";
            }
        }
        if (isset($manifest['type']) && !in_array($manifest['type'], ['module', 'plugin', 'theme'], true)) {
            $errors[] = 'type должен быть module, plugin или theme.';
        }
        return $errors;
    }

    public function dangerousPermissions(array $manifest): array
    {
        $permissions = is_array($manifest['permissions'] ?? null) ? $manifest['permissions'] : [];
        return array_values(array_intersect($permissions, self::DANGEROUS));
    }
}
