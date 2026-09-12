<?php

declare(strict_types=1);

namespace CajeerEngine\ExtensionSdk;

final readonly class ExtensionContext
{
    /**
     * @param array<string, mixed> $config
     * @param list<string> $permissions
     * @param list<string> $events
     */
    public function __construct(
        public string $engineVersion,
        public string $engineApiVersion,
        public string $extensionPath,
        public string $name = '',
        public string $type = '',
        public array $config = [],
        public array $permissions = [],
        public array $events = [],
    ) {
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
