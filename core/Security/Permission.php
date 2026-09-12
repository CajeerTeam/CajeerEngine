<?php

declare(strict_types=1);

namespace CajeerEngine\Security;

final readonly class Permission
{
    public function __construct(public string $scope)
    {
    }
}
