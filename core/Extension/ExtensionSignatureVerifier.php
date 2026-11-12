<?php

declare(strict_types=1);

namespace CajeerEngine\Extension;

final readonly class ExtensionSignatureVerifier
{
    public function __construct(private bool $allowUnsignedInDev = true)
    {
    }

    /** @return array{ok:bool,status:string,expected:?string,actual:string,warnings:list<string>} */
    public function verify(string $manifestPath, ExtensionManifest $manifest): array
    {
        try { return PackageTrust::verify(dirname($manifestPath),$manifest,(string)(getenv('EXTENSIONS_TRUST_DIR')?:''),$this->allowUnsignedInDev && in_array(getenv('APP_ENV'),['dev','local','testing'],true)); }
        catch (\Throwable $e) { return ['ok'=>false,'status'=>'invalid','expected'=>null,'actual'=>'','warnings'=>[$e->getMessage()]]; }
    }

    /** @param array<string, mixed> $raw */
    public function canonicalHash(array $raw): string
    {
        unset($raw['signature'], $raw['signatures']);
        $this->ksortRecursive($raw);
        return hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $raw */
    private function expectedHash(array $raw): ?string
    {
        $signature = $raw['signature'] ?? $raw['signatures'] ?? null;
        if (is_string($signature)) {
            return $signature;
        }
        if (is_array($signature) && isset($signature['sha256']) && is_string($signature['sha256'])) {
            return $signature['sha256'];
        }
        return null;
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
    }
}
