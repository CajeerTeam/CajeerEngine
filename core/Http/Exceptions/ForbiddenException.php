<?php

declare(strict_types=1);

namespace Cajeer\Http\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Доступ запрещён')
    {
        parent::__construct(403, $message);
    }
}
