<?php

declare(strict_types=1);
namespace CajeerEngine\Release;

/** Compare Composer's actual no-dev installation against the committed lock. */
final class ReleaseDependencyInventory
{
    public const AUTOLOAD_FILES = ['vendor/autoload.php', 'vendor/composer/installed.json', 'vendor/composer/installed.php', 'vendor/composer/autoload_real.php', 'vendor/composer/autoload_psr4.php', 'vendor/composer/autoload_static.php', 'vendor/composer/autoload_classmap.php', 'vendor/composer/platform_check.php'];

    /** @return list<string> Runtime packages that must be present in vendor. */
    public static function validate(string $lockJson, string $installedJson): array
    {
        $lock = json_decode($lockJson, true, 512, JSON_THROW_ON_ERROR);
        $installed = json_decode($installedJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($lock['packages'] ?? null) || $lock['packages'] === [] || !is_array($installed['packages'] ?? null) || ($installed['dev'] ?? true) !== false) throw new \RuntimeException('Composer production dependency inventory is missing');
        $map = static function (array $packages): array {
            $out = [];
            foreach ($packages as $package) {
                $name = $package['name'] ?? '';
                if (!is_string($name) || !preg_match('~^[a-z0-9_.-]+/[a-z0-9_.-]+$~D', $name) || isset($out[$name]) || !is_string($package['version'] ?? null)) throw new \RuntimeException('Invalid Composer package inventory');
                $out[$name] = [$package['version'], $package['dist']['reference'] ?? $package['source']['reference'] ?? null, $package['type'] ?? 'library'];
            }
            ksort($out);
            return $out;
        };
        $expected = $map($lock['packages']);
        if ($expected !== $map($installed['packages'])) throw new \RuntimeException('Installed Composer packages differ from composer.lock');
        return array_keys(array_filter($expected, static fn (array $package): bool => $package[2] !== 'metapackage'));
    }
}
