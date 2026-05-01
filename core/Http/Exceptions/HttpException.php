<?php

declare(strict_types=1);

namespace Cajeer\Http\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(private readonly int $statusCode, string $message)
    {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
