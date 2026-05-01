<?php

declare(strict_types=1);

namespace Cajeer\Http\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(422, 'Ошибка валидации');
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
