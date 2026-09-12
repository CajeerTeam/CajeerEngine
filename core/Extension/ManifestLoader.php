<?php

declare(strict_types=1);

namespace CajeerEngine\Extension;

final readonly class ManifestLoader
{
    public const FILENAME = 'cajeer.extension.json';

    public function load(string $path): ExtensionManifest
    {
        if (is_dir($path)) {
            $path = rtrim($path, '/') . '/' . self::FILENAME;
        }
        if (!is_file($path)) {
            throw new \RuntimeException('Manifest расширения не найден: ' . $path);
        }

        $payload = json_decode(file_get_contents($path) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Manifest расширения должен быть JSON-объектом: ' . $path);
        }

        $errors = $this->validatePayload($payload);
        if ($errors !== []) {
            throw new \RuntimeException('Manifest невалиден: ' . implode('; ', $errors));
        }

        return new ExtensionManifest(
            name: strtolower(trim((string) $payload['name'])),
            type: strtolower(trim((string) $payload['type'])),
            version: trim((string) $payload['version']),
            engineConstraint: trim((string) $payload['engine']),
            title: trim((string) ($payload['title'] ?? $payload['name'])),
            description: trim((string) ($payload['description'] ?? '')),
            permissions: $this->stringList($payload['permissions'] ?? []),
            events: $this->stringList($payload['events'] ?? []),
            providers: $this->stringList($payload['providers'] ?? []),
            config: is_array($payload['config'] ?? null) ? $payload['config'] : [],
            raw: $this->normalizeRaw($payload),
            path: dirname($path),
        );
    }

    /** @param array<string, mixed> $payload @return list<string> */
    public function validatePayload(array $payload): array
    {
        $errors = [];
        foreach (['name', 'type', 'version', 'engine'] as $required) {
            if (!isset($payload[$required]) || !is_string($payload[$required]) || trim($payload[$required]) === '') {
                $errors[] = "Поле обязательно: {$required}";
            }
        }

        if (isset($payload['name']) && is_string($payload['name']) && !preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', strtolower($payload['name']))) {
            $errors[] = 'name должен быть в формате vendor/name и содержать только a-z, 0-9, _, ., -';
        }
        if (isset($payload['type']) && is_string($payload['type']) && !in_array(strtolower($payload['type']), ['module', 'plugin', 'theme'], true)) {
            $errors[] = 'type должен быть module, plugin или theme';
        }
        if (isset($payload['version']) && is_string($payload['version']) && !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $payload['version'])) {
            $errors[] = 'version должен быть SemVer, например 1.2.3';
        }
        if (isset($payload['engine']) && is_string($payload['engine']) && trim($payload['engine']) === '') {
            $errors[] = 'engine не должен быть пустым';
        }

        foreach (['permissions', 'events', 'providers'] as $field) {
            if (isset($payload[$field]) && !is_array($payload[$field])) {
                $errors[] = "{$field} должен быть массивом строк";
                continue;
            }
            if (isset($payload[$field]) && is_array($payload[$field])) {
                foreach ($payload[$field] as $value) {
                    if (!is_string($value) || trim($value) === '') {
                        $errors[] = "{$field} должен содержать только непустые строки";
                        break;
                    }
                }
            }
        }

        if (isset($payload['config']) && !is_array($payload['config'])) {
            $errors[] = 'config должен быть объектом';
        }

        if (isset($payload['hooks']) && !is_array($payload['hooks'])) {
            $errors[] = 'hooks должен быть объектом event => method';
        }
        if (isset($payload['assets']) && !is_array($payload['assets'])) {
            $errors[] = 'assets должен быть объектом';
        }
        if (isset($payload['migrations']) && !is_array($payload['migrations'])) {
            $errors[] = 'migrations должен быть объектом';
        }

        return $errors;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizeRaw(array $payload): array
    {
        $payload['hooks'] = is_array($payload['hooks'] ?? null) ? $payload['hooks'] : [];
        $payload['assets'] = is_array($payload['assets'] ?? null) ? $payload['assets'] : ['source' => 'assets', 'public' => true];
        $payload['migrations'] = is_array($payload['migrations'] ?? null) ? $payload['migrations'] : ['path' => 'migrations'];
        $payload['lifecycle'] = is_array($payload['lifecycle'] ?? null) ? $payload['lifecycle'] : [];
        return $payload;
    }

    /** @param mixed $value @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }
        return array_values(array_unique($result));
    }
}
