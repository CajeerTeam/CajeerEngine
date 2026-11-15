<?php

declare(strict_types=1);

namespace CajeerEngine\Integration\Provider;

final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function register(ProviderInterface $provider): void
    {
        $id = $provider->id();
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) || isset($this->providers[$id])) {
            throw new \InvalidArgumentException('Invalid or duplicate integration provider.');
        }
        $this->providers[$id] = $provider;
    }

    public function get(string $id): ProviderInterface
    {
        return $this->providers[$id] ?? throw new \InvalidArgumentException('Unknown integration provider.');
    }

    public function descriptors(): array
    {
        return array_values(array_map(static fn (ProviderInterface $p): array => $p->descriptor(), $this->providers));
    }

    public static function defaults(): self
    {
        $registry = new self();
        $registry->register(new HttpProvider(false));
        $registry->register(new HttpProvider(true));
        return $registry;
    }
}
