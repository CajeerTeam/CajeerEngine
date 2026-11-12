<?php

declare(strict_types=1);
namespace CajeerEngine\Api;

/** JSON is a YAML 1.2 subset. A single OpenAPI file is used by runtime and tooling. */
final class ApiContract
{
    private array $document;
    private array $operations = [];

    public function __construct(string $root)
    {
        $this->document = json_decode((string) file_get_contents($root . '/api/openapi.yaml'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->document['paths'] ?? [] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get','post','put','patch','delete'], true) && is_array($operation)) {
                    $this->operations[$operation['x-cajeer-route'] ?? ''] = $operation;
                }
            }
        }
    }

    public function document(): array { return $this->document; }
    public function operation(string $route): ?array { return $this->operations[$route] ?? null; }

    /** Validate the JSON Schema subset used by request schemas (refs, types, const, enum, object, array, bounds). */
    public function validate(mixed $value, array $schema, string $path = '$', int $depth = 0): array
    {
        if ($depth > 64) return [$path . ': schema nesting limit'];
        if (isset($schema['$ref'])) {
            $ref = $schema['$ref'];
            $prefix = '#/components/schemas/';
            if (!is_string($ref) || !str_starts_with($ref, $prefix) || !isset($this->document['components']['schemas'][substr($ref, strlen($prefix))])) return [$path . ': invalid schema reference'];
            return $this->validate($value, $this->document['components']['schemas'][substr($ref, strlen($prefix))], $path, $depth + 1);
        }
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $alternative) if ($this->validate($value, $alternative, $path, $depth + 1) === []) return [];
            return [$path . ': does not match any permitted schema'];
        }
        if (array_key_exists('const', $schema) && $schema['const'] !== $value) return [$path . ': prohibited value'];
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) return [$path . ': invalid enum value'];
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $valid = false;
            foreach ($types as $type) {
                $valid = $valid || match ($type) {
                    'object' => $value instanceof \stdClass,
                    'array' => is_array($value) && array_is_list($value),
                    'string' => is_string($value), 'boolean' => is_bool($value), 'integer' => is_int($value),
                    'number' => is_int($value) || is_float($value), 'null' => $value === null,
                    default => false,
                };
            }
            if (!$valid) return [$path . ': expected ' . implode('|', $types)];
        }
        $errors = [];
        if ($value instanceof \stdClass) {
            foreach ($schema['required'] ?? [] as $field) if (!property_exists($value, $field)) $errors[] = $path . '.' . $field . ': required';
            foreach (get_object_vars($value) as $field => $fieldValue) {
                if (isset($schema['properties'][$field])) $errors = array_merge($errors, $this->validate($fieldValue, $schema['properties'][$field], $path . '.' . $field, $depth + 1));
                elseif (($schema['additionalProperties'] ?? true) === false) $errors[] = $path . '.' . $field . ': unknown property';
                if (count($errors) >= 32) break;
            }
        } elseif (is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = array_merge($errors, $this->validate($item, $schema['items'], $path . '[' . $i . ']', $depth + 1));
                if (count($errors) >= 32) break;
            }
        } elseif (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) $errors[] = $path . ': too short';
            if (isset($schema['pattern']) && preg_match('~' . str_replace('~', '\\~', $schema['pattern']) . '~D', $value) !== 1) $errors[] = $path . ': invalid format';
            if (($schema['format'] ?? '') === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[] = $path . ': invalid email';
        } elseif (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) $errors[] = $path . ': below minimum';
            if (isset($schema['maximum']) && $value > $schema['maximum']) $errors[] = $path . ': above maximum';
        }
        return array_slice($errors, 0, 32);
    }
}
