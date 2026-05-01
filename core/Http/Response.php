<?php

declare(strict_types=1);

namespace Cajeer\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $content = '',
        private int $status = 200,
        private array $headers = []
    ) {}

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->content;
    }

    public function content(): string
    {
        return $this->content;
    }
}
