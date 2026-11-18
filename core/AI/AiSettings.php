<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Integration\SecretVault;
use CajeerEngine\Update\OperationFiles as Files;

/** The provider credential is envelope-encrypted on disk and never returned to a browser. */
final readonly class AiSettings
{
    public function __construct(private string $root) {}

    private function path(): string { return Files::path($this->root, 'storage/app/ai/settings.json'); }
    private function lockPath(): string { return Files::path($this->root, 'storage/app/ai/settings.lock'); }

    private function locked(callable $callback, bool $exclusive = false): mixed
    {
        Files::dir(Files::path($this->root, 'storage/app/ai'), 0700);
        $handle = fopen($this->lockPath(), 'c+');
        if ($handle === false) throw new \RuntimeException('AI settings lock unavailable.');
        try {
            Files::permissions($this->lockPath(), 0600);
            if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) throw new \RuntimeException('AI settings lock failed.');
            $path = $this->path();
            $data = is_file($path) ? Files::read($path) : [];
            if (!is_array($data)) throw new \RuntimeException('AI settings corrupted.');
            $result = $callback($data);
            if ($exclusive) Files::json($path, $data, 0600);
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function status(): array
    {
        return $this->locked(function (array $data): array {
            return [
                'configured' => !empty($data['key_ciphertext']) || trim((string)(getenv('OPENAI_API_KEY') ?: '')) !== '',
                'credential_source' => !empty($data['key_ciphertext']) ? 'vault' : ((string)getenv('OPENAI_API_KEY') !== '' ? 'environment' : 'none'),
                'model' => (string)($data['model'] ?? 'gpt-4.1-mini'),
                'max_output_tokens' => (int)($data['max_output_tokens'] ?? 1024),
                'monthly_token_limit' => (int)($data['monthly_token_limit'] ?? 250000),
            ];
        });
    }

    public function key(): string
    {
        return $this->locked(function (array $data): string {
            if (!empty($data['key_ciphertext'])) {
                return SecretVault::fromEnvironment()->open('cajeerengine:ai:openai:key', $data['key_ciphertext']);
            }
            $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
            if ($key === '') throw new \DomainException('OpenAI API key is not configured.');
            return $key;
        });
    }

    public function update(array $body): array
    {
        $allowed = ['api_key', 'remove_key', 'model', 'max_output_tokens', 'monthly_token_limit'];
        foreach (array_keys($body) as $name) {
            if (!in_array($name, $allowed, true)) throw new \InvalidArgumentException('Unknown AI setting.');
        }
        return $this->locked(function (array &$data) use ($body): void {
            if (!empty($body['remove_key'])) unset($data['key_ciphertext']);
            if (array_key_exists('api_key', $body) && $body['api_key'] !== '') {
                $key = $body['api_key'];
                if (!is_string($key) || !preg_match('/^sk-[\x21-\x7e]{12,500}$/D', $key)) throw new \InvalidArgumentException('Invalid OpenAI API key.');
                $data['key_ciphertext'] = SecretVault::fromEnvironment()->seal('cajeerengine:ai:openai:key', $key);
            }
            if (array_key_exists('model', $body)) {
                if (!is_string($body['model']) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,79}$/D', $body['model'])) throw new \InvalidArgumentException('Invalid model ID.');
                $data['model'] = $body['model'];
            }
            foreach (['max_output_tokens' => [128, 4096], 'monthly_token_limit' => [1000, 10000000]] as $name => [$min, $max]) {
                if (array_key_exists($name, $body)) {
                    if (!is_int($body[$name]) || $body[$name] < $min || $body[$name] > $max) throw new \InvalidArgumentException('Invalid ' . $name . '.');
                    $data[$name] = $body[$name];
                }
            }
        }, true) ?? $this->status();
    }
}
