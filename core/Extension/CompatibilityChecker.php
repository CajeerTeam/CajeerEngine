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
        return $this->matches($this->normalize($this->engineApiVersion), $manifest->engineConstraint);
    }

    public function assertCompatible(ExtensionManifest $manifest): void
    {
        if (!$this->isCompatible($manifest)) {
            throw new \RuntimeException(sprintf(
                'Расширение %s требует Engine API %s, текущая версия Engine API: %s.',
                $manifest->name,
                $manifest->engineConstraint,
                $this->engineApiVersion,
            ));
        }
    }

    private function matches(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s+/', $constraint) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '^')) {
                if (!$this->matchesCaret($version, substr($part, 1))) {
                    return false;
                }
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|=)?(.+)$/', $part, $m)) {
                $operator = $m[1] !== '' ? $m[1] : '=';
                $target = $this->normalize($m[2]);
                if (!$this->compare($version, $operator, $target)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function matchesCaret(string $version, string $target): bool
    {
        $current = $this->parts($version);
        $wanted = $this->parts($this->normalize($target));
        if ($wanted[0] === 0) {
            return $current[0] === 0 && $current[1] === $wanted[1] && version_compare($version, $this->normalize($target), '>=');
        }
        return $current[0] === $wanted[0] && version_compare($version, $this->normalize($target), '>=');
    }

    private function compare(string $current, string $operator, string $target): bool
    {
        return match ($operator) {
            '>' => version_compare($current, $target, '>'),
            '>=' => version_compare($current, $target, '>='),
            '<' => version_compare($current, $target, '<'),
            '<=' => version_compare($current, $target, '<='),
            '=', '==' => version_compare($current, $target, '='),
            default => false,
        };
    }

    private function normalize(string $version): string
    {
        $version = trim($version);
        $version = preg_replace('/[^0-9.].*$/', '', $version) ?: $version;
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }
        return implode('.', array_slice($parts, 0, 3));
    }

    /** @return array{0:int,1:int,2:int} */
    private function parts(string $version): array
    {
        $parts = array_map('intval', explode('.', $version));
        return [$parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0];
    }
}
