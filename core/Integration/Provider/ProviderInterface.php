<?php

declare(strict_types=1);

namespace CajeerEngine\Integration\Provider;

interface ProviderInterface
{
    public function id(): string;
    /** Public metadata, never credentials. */
    public function descriptor(): array;
    /** Validate and normalize provider-specific public configuration. */
    public function configuration(array $config): array;
    /** Validate credentials before they are stored or used. */
    public function validateSecret(#[\SensitiveParameter] string $secret): void;
    /** Perform an actual operation; return only safe status metadata. */
    public function execute(array $config, #[\SensitiveParameter] string $secret, string $event, array|\stdClass $payload, string $eventId): int;
}
