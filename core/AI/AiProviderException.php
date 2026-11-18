<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

final class AiProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 502)
    {
        parent::__construct($message);
    }
}
