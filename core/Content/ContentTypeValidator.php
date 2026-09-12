<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

final class ContentTypeValidator
{
    private const FIELD_TYPES = ['text', 'textarea', 'richtext', 'number', 'boolean', 'date', 'datetime', 'media', 'relation', 'blocks', 'json'];

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function normalize(array $payload): array
    {
        $handle = trim((string) ($payload['handle'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));

        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $handle)) {
            throw new \InvalidArgumentException('handle должен начинаться с латинской буквы и содержать только a-z, 0-9 и _. Минимум 2 символа.');
        }

        if ($name === '') {
            throw new \InvalidArgumentException('name обязателен.');
        }

        return [
            'handle' => $handle,
            'name' => $name,
            'fields' => self::fields($payload['fields'] ?? []),
            'blocks' => self::blocks($payload['blocks'] ?? []),
            'localized' => filter_var($payload['localized'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'revisionable' => filter_var($payload['revisionable'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** @param mixed $fields @return list<array<string, mixed>> */
    private static function fields(mixed $fields): array
    {
        if ($fields === null) {
            return [];
        }
        if (!is_array($fields)) {
            throw new \InvalidArgumentException('fields должен быть массивом.');
        }

        $result = [];
        $handles = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new \InvalidArgumentException('Каждое поле должно быть объектом.');
            }

            $handle = trim((string) ($field['handle'] ?? ''));
            $type = trim((string) ($field['type'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));

            if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $handle)) {
                throw new \InvalidArgumentException('Некорректный handle поля: ' . ($handle ?: '<empty>'));
            }
            if (isset($handles[$handle])) {
                throw new \InvalidArgumentException('Дублирующийся handle поля: ' . $handle);
            }
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new \InvalidArgumentException('Неподдерживаемый тип поля: ' . $type);
            }
            if ($label === '') {
                throw new \InvalidArgumentException('label обязателен для поля: ' . $handle);
            }

            $handles[$handle] = true;
            $result[] = [
                'handle' => $handle,
                'type' => $type,
                'label' => $label,
                'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'localized' => filter_var($field['localized'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'settings' => is_array($field['settings'] ?? null) ? $field['settings'] : [],
            ];
        }

        return $result;
    }

    /** @param mixed $blocks @return list<array<string, mixed>> */
    private static function blocks(mixed $blocks): array
    {
        if ($blocks === null) {
            return [];
        }
        if (!is_array($blocks)) {
            throw new \InvalidArgumentException('blocks должен быть массивом.');
        }

        $result = [];
        $handles = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                throw new \InvalidArgumentException('Каждый block должен быть объектом.');
            }

            $handle = trim((string) ($block['handle'] ?? ''));
            $name = trim((string) ($block['name'] ?? ''));
            if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $handle)) {
                throw new \InvalidArgumentException('Некорректный handle блока: ' . ($handle ?: '<empty>'));
            }
            if (isset($handles[$handle])) {
                throw new \InvalidArgumentException('Дублирующийся handle блока: ' . $handle);
            }
            if ($name === '') {
                throw new \InvalidArgumentException('name обязателен для блока: ' . $handle);
            }

            $handles[$handle] = true;
            $result[] = [
                'handle' => $handle,
                'name' => $name,
                'fields' => self::fields($block['fields'] ?? []),
            ];
        }

        return $result;
    }
}
