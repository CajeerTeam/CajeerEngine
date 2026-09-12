<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final class Rbac
{
    /** @param list<string> $userScopes */
    public function allows(array $userScopes, string $requiredScope): bool
    {
        return in_array('*', $userScopes, true) || in_array($requiredScope, $userScopes, true);
    }
}
