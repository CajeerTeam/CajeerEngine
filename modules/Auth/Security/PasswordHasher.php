<?php

declare(strict_types=1);

namespace Cajeer\Modules\Auth\Security;

final class PasswordHasher
{
    public function make(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
