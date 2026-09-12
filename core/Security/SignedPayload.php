<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final readonly class SignedPayload
{
    public function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public function verify(string $payload, string $signature, string $secret): bool
    {
        return hash_equals($this->sign($payload, $secret), $signature);
    }
}
