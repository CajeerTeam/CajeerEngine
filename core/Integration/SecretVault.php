<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

/** Authenticated encryption; bind each credential to its integration and provider. */
final readonly class SecretVault
{
    public function __construct(#[\SensitiveParameter] private string $masterKey) {}

    private function key(): string
    {
        $raw = str_starts_with($this->masterKey, 'base64:') ? base64_decode(substr($this->masterKey, 7), true) : false;
        if ($raw === false || strlen($raw) !== 32) throw new \RuntimeException('Integration encryption key unavailable; configure a 32-byte base64 key.');
        return sodium_crypto_generichash('cajeerengine:integration-vault:v1', $raw, 32);
    }

    public function seal(string $context, #[\SensitiveParameter] string $secret): string
    {
        $key = $this->key();
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, $context, $nonce, $key));
        } finally { sodium_memzero($key); }
    }

    public function open(string $context, string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw)<$n+SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) throw new \RuntimeException('Integration credential is invalid.');
        $key = $this->key();
        try {
            $secret = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw,$n), $context, substr($raw,0,$n), $key);
            if ($secret === false) throw new \RuntimeException('Integration credential cannot be decrypted.');
            return $secret;
        } finally { sodium_memzero($key); }
    }
}
