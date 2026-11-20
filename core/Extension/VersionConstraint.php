<?php

declare(strict_types=1);

namespace CajeerEngine\Extension;

/** Strict AND constraints for Engine API and installed extension dependencies. */
final class VersionConstraint
{
    public static function matches(string $version, string $constraint): bool
    {
        $current = self::normalizeVersion($version);
        foreach (self::conditions($constraint) as [$operator, $target, $components]) {
            if ($operator === '*') {
                continue;
            }
            if ($operator === '^' || $operator === '~') {
                [$major, $minor, $patch] = array_map('intval', explode('.', $target));
                if ($operator === '~') {
                    $upper = $major . '.' . ($minor + 1) . '.0';
                } elseif ($major > 0) {
                    $upper = ($major + 1) . '.0.0';
                } elseif ($minor > 0) {
                    $upper = '0.' . ($minor + 1) . '.0';
                } elseif ($components === 2) {
                    $upper = '0.1.0';
                } else {
                    $upper = '0.0.' . ($patch + 1);
                }
                if (version_compare($current, $target, '<') || version_compare($current, $upper, '>=')) {
                    return false;
                }
                continue;
            }
            if (!version_compare($current, $target, $operator)) {
                return false;
            }
        }
        return true;
    }

    public static function assertValid(string $constraint): void
    {
        self::conditions($constraint);
    }

    /** @return list<array{0:string,1:string,2:int}> */
    private static function conditions(string $constraint): array
    {
        $constraint = trim($constraint);
        if ($constraint === '*') {
            return [['*', '', 0]];
        }
        if ($constraint === '' || strlen($constraint) > 256 || str_contains($constraint, '||')) {
            throw new \InvalidArgumentException('Invalid extension version constraint');
        }
        $conditions = [];
        foreach (preg_split('/[\s,]+/', $constraint) ?: [] as $piece) {
            if ($piece === '') {
                continue;
            }
            if (!preg_match('/^(\^|~|>=|<=|>|<|==|=)?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?)$/D', $piece, $match)) {
                throw new \InvalidArgumentException('Unsupported extension version constraint: ' . $piece);
            }
            $conditions[] = [$match[1] === '' ? '=' : $match[1], self::normalizeVersion($match[2]), substr_count($match[2], '.') + 1];
        }
        if ($conditions === []) {
            throw new \InvalidArgumentException('Empty extension version constraint');
        }
        return $conditions;
    }

    private static function normalizeVersion(string $version): string
    {
        if (!preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?(?:\+[0-9A-Za-z][0-9A-Za-z.-]*)?$/D', $version)) {
            throw new \InvalidArgumentException('Invalid extension or Engine API version');
        }
        $version = explode('+', $version, 2)[0]; // SemVer build metadata has no ordering effect.
        $pieces = explode('-', $version, 2);
        if (substr_count($pieces[0], '.') === 1) {
            $pieces[0] .= '.0';
        }
        return implode('-', $pieces);
    }
}
