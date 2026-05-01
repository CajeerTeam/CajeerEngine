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
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
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
}
