<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final readonly class ApiToken
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $name,
        public string $tokenHash,
        public array $scopes,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
