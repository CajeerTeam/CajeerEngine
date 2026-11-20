<?php

declare(strict_types=1);

namespace CajeerEngine\Integration;

/**
 * Versioned authenticated encryption with explicit key IDs and legacy unlabelled
 * ciphertext support. Context remains authenticated additional data (AAD).
 * Old keys are *decrypt-only*; new writes always use the current key.
 */
final readonly class SecretVault
{
    /** @param list<string> $previousKeys */
    public function __construct(#[\SensitiveParameter] private string $masterKey, #[\SensitiveParameter] private array $previousKeys = []) {}

    public static function fromEnvironment(string $purpose = 'integrations'): self
    {
        if (!in_array($purpose, ['integrations', 'oidc'], true)) throw new \InvalidArgumentException('Unknown encryption domain.');
        $key = (string)(getenv('INTEGRATIONS_KEY') ?: getenv('APP_KEY') ?: '');
        $previous = self::parsePrevious((string)(getenv('INTEGRATIONS_PREVIOUS_KEYS') ?: ''));
        if ($purpose === 'oidc' && (string)getenv('SKIF4ER_ID_TRANSACTION_KEY') !== '') {
            $key = (string)getenv('SKIF4ER_ID_TRANSACTION_KEY');
            // The independent OIDC key may have previously fallen back to the integration key.
            $previous = array_merge(self::parsePrevious((string)(getenv('SKIF4ER_ID_TRANSACTION_PREVIOUS_KEYS') ?: '')), $previous);
        } elseif ($purpose === 'oidc') {
            $previous = array_merge(self::parsePrevious((string)(getenv('SKIF4ER_ID_TRANSACTION_PREVIOUS_KEYS') ?: '')), $previous);
        }
        return new self($key, $previous);
    }

    /** @return list<string> */
    private static function parsePrevious(string $keys): array
    {
        if ($keys === '') return [];
        $parts = array_map('trim', explode(',', $keys));
        if (count($parts) > 8 || in_array('', $parts, true)) throw new \RuntimeException('Invalid previous encryption keys list.');
        return $parts;
    }

    private static function rawKey(string $encoded): string
    {
        $raw = str_starts_with($encoded, 'base64:') ? base64_decode(substr($encoded, 7), true) : false;
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('Vault encryption key unavailable; configure a 32-byte base64 key.');
        }
        return $raw;
    }

    private static function id(string $raw): string
    {
        return substr(hash('sha256', 'cajeerengine:vault:kid:v2:' . $raw), 0, 20);
    }

    /** @return array{0:string,1:array<string,string>} Derived keys indexed by stable IDs. */
    private function keys(): array
    {
        $raw = self::rawKey($this->masterKey);
        $active = self::id($raw);
        $keys = [$active => sodium_crypto_generichash('cajeerengine:integration-vault:v1', $raw, 32)];
        sodium_memzero($raw);
        foreach ($this->previousKeys as $previous) {
            $raw = self::rawKey($previous);
            $id = self::id($raw);
            if (!isset($keys[$id])) $keys[$id] = sodium_crypto_generichash('cajeerengine:integration-vault:v1', $raw, 32);
            sodium_memzero($raw);
        }
        return [$active, $keys];
    }

    public function activeKeyId(): string
    {
        $raw = self::rawKey($this->masterKey);
        try { return self::id($raw); } finally { sodium_memzero($raw); }
    }

    public function needsRotation(string $encoded): bool
    {
        return !str_starts_with($encoded, 'v2:' . $this->activeKeyId() . ':');
    }

    public function seal(string $context, #[\SensitiveParameter] string $secret): string
    {
        [$active, $keys] = $this->keys();
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            return 'v2:' . $active . ':' . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret, $context, $nonce, $keys[$active]));
        } finally { foreach ($keys as &$key) sodium_memzero($key); unset($key); }
    }

    public function open(string $context, string $encoded): string
    {
        [$active, $keys] = $this->keys();
        try {
            $candidates = $keys;
            if (str_starts_with($encoded, 'v2:')) {
                if (!preg_match('/^v2:([a-f0-9]{20}):([A-Za-z0-9+\/=]+)$/D', $encoded, $matches)) {
                    throw new \RuntimeException('Invalid vault envelope.');
                }
                if (!isset($keys[$matches[1]])) throw new \RuntimeException('Encryption key is not available in keyring.');
                $candidates = [$matches[1] => $keys[$matches[1]]];
                $encoded = $matches[2];
            }
            $data = base64_decode($encoded, true);
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if ($data === false || strlen($data) < $n + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
                throw new \RuntimeException('Invalid vault ciphertext.');
            }
            foreach ($candidates as $key) {
                $secret = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($data, $n), $context, substr($data, 0, $n), $key);
                if ($secret !== false) return $secret;
            }
            throw new \RuntimeException('Vault credential cannot be decrypted.');
        } finally { foreach ($keys as &$key) sodium_memzero($key); unset($key); }
    }

    /** Decrypt, authenticate, and re-encrypt if the envelope does not use the active key. */
    public function rewrap(string $context, string $encoded): string
    {
        if (!$this->needsRotation($encoded)) {
            // Even current-key data must be authenticated during the rotation audit.
            $secret = $this->open($context, $encoded);
            sodium_memzero($secret);
            return $encoded;
        }
        $secret = $this->open($context, $encoded);
        try { return $this->seal($context, $secret); }
        finally { sodium_memzero($secret); }
    }
}
