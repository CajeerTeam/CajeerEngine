<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

final readonly class ContentSchemaGenerator
{
    /** @param array<string, mixed> $contentType @return array<string, mixed> */
    public function jsonSchema(array $contentType): array
    {
        $properties = [];
        $required = [];

        foreach ((array) ($contentType['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $handle = (string) ($field['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $properties[$handle] = $this->fieldSchema($field);
            if ((bool) ($field['required'] ?? false)) {
                $required[] = $handle;
            }
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => (string) ($contentType['name'] ?? $contentType['handle'] ?? 'ContentEntry'),
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private function fieldSchema(array $field): array
    {
        $type = (string) ($field['type'] ?? 'text');
        $schema = match ($type) {
            'text', 'textarea', 'richtext' => ['type' => ['string', 'null']],
            'number' => ['type' => ['number', 'integer', 'null']],
            'boolean' => ['type' => ['boolean', 'null']],
            'date' => ['type' => ['string', 'null'], 'format' => 'date'],
            'datetime' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'media', 'relation' => ['oneOf' => [
                ['type' => 'string'],
                ['type' => 'array', 'items' => ['type' => 'string']],
                ['type' => 'null'],
            ]],
            'blocks' => ['type' => ['array', 'null'], 'items' => [
                'type' => 'object',
                'required' => ['handle', 'data'],
                'properties' => [
                    'handle' => ['type' => 'string'],
                    'data' => ['type' => 'object'],
                ],
            ]],
            'json' => true,
            default => ['type' => ['string', 'null']],
        };

        if (isset($field['label'])) {
            $schema['title'] = (string) $field['label'];
        }
        if (is_array($field['settings'] ?? null)) {
            $settings = $field['settings'];
            if (isset($settings['max_length'])) {
                $schema['maxLength'] = (int) $settings['max_length'];
            }
            if (isset($settings['min_length'])) {
                $schema['minLength'] = (int) $settings['min_length'];
            }
        }

        return $schema;
    }
}
