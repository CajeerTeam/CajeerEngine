<?php

declare(strict_types=1);

namespace CajeerEngine\Extension;

final readonly class ExtensionManifest
{
    /**
     * @param list<string> $permissions
     * @param list<string> $events
     * @param list<string> $providers
     * @param array<string, mixed> $config
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $version,
        public string $engineConstraint,
        public string $title = '',
        public string $description = '',
        public array $permissions = [],
        public array $events = [],
        public array $providers = [],
        public array $config = [],
        public array $raw = [],
        public ?string $path = null,
    ) {
    }

    public function slug(): string
    {
        return str_replace(['/', ':'], ['--', '-'], strtolower($this->name));
    }

    public function shortName(): string
    {
        $parts = explode('/', $this->name, 2);
        return $parts[1] ?? $this->name;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug(),
            'type' => $this->type,
            'version' => $this->version,
            'engine' => $this->engineConstraint,
            'title' => $this->title !== '' ? $this->title : $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions,
            'events' => $this->events,
            'providers' => $this->providers,
            'config' => $this->config,
            'hooks' => is_array($this->raw['hooks'] ?? null) ? $this->raw['hooks'] : [],
            'assets' => is_array($this->raw['assets'] ?? null) ? $this->raw['assets'] : [],
            'migrations' => is_array($this->raw['migrations'] ?? null) ? $this->raw['migrations'] : [],
            'lifecycle' => is_array($this->raw['lifecycle'] ?? null) ? $this->raw['lifecycle'] : [],
            'path' => $this->path,
        ];
    }
}
