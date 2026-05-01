<?php

declare(strict_types=1);

namespace Cajeer\Http;

final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,mixed> $server */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files = [],
        public readonly array $cookies = []
    ) {}

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self(
            $method,
            '/' . ltrim($uri, '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function only(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->post)) {
                $data[$key] = $this->post[$key];
            }
        }
        return $data;
    }

    public function path(): string
    {
        return '/' . trim($this->uri, '/');
    }

    public function is(string $pattern): bool
    {
        $pattern = '/' . trim($pattern, '/');
        if ($pattern === $this->path()) {
            return true;
        }
        $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $this->path());
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json') || $this->is('api/*');
    }

    public function ip(): string
    {
        return (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? $this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }
}
