<?php

declare(strict_types=1);

namespace CajeerEngine\Rc;

final readonly class CheckResult
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $group,
        public string $name,
        public string $status,
        public string $message,
        public array $context = [],
    ) {
    }

    public static function pass(string $group, string $name, string $message, array $context = []): self
    {
        return new self($group, $name, 'pass', $message, $context);
    }

    public static function warn(string $group, string $name, string $message, array $context = []): self
    {
        return new self($group, $name, 'warn', $message, $context);
    }

    public static function fail(string $group, string $name, string $message, array $context = []): self
    {
        return new self($group, $name, 'fail', $message, $context);
    }

    /** @return array{group:string,name:string,status:string,message:string,context:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
