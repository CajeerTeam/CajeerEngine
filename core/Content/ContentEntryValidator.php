<?php

declare(strict_types=1);

namespace CajeerEngine\Content;

final readonly class ContentEntryValidator
{
    /** @param array<string, mixed> $contentType */
    public function __construct(private array $contentType)
    {
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function normalizeForCreate(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            throw new \InvalidArgumentException('data должен быть объектом.');
        }

        $status = (string) ($payload['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('status должен быть draft, published или archived.');
        }

        return [
            'title' => $this->title($payload, $data),
            'slug' => self::slug((string) ($payload['slug'] ?? $this->title($payload, $data))),
            'status' => $status,
            'locale' => self::locale((string) ($payload['locale'] ?? 'ru')),
            'data' => $this->data($data),
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $existing @return array<string, mixed> */
    public function normalizeForUpdate(array $payload, array $existing): array
    {
        $data = $payload['data'] ?? ($existing['data'] ?? []);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('data должен быть объектом.');
        }

        $status = (string) ($payload['status'] ?? ($existing['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('status должен быть draft, published или archived.');
        }

        $title = array_key_exists('title', $payload) ? trim((string) $payload['title']) : (string) ($existing['title'] ?? '');
        if ($title === '') {
            $title = $this->title($payload, $data);
        }

        $slugSource = array_key_exists('slug', $payload) ? (string) $payload['slug'] : (string) ($existing['slug'] ?? $title);

        return [
            'title' => $title,
            'slug' => self::slug($slugSource),
            'status' => $status,
            'locale' => self::locale((string) ($payload['locale'] ?? ($existing['locale'] ?? 'ru'))),
            'data' => $this->data($data),
        ];
    }

    /** @return list<string> */
    public function errors(array $payload, ?array $existing = null): array
    {
        try {
            $existing === null ? $this->normalizeForCreate($payload) : $this->normalizeForUpdate($payload, $existing);
            return [];
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $data */
    private function title(array $payload, array $data): string
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        foreach (['title', 'name', 'heading'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return 'Без названия';
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function data(array $data): array
    {
        $fields = $this->contentType['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $handle = (string) ($field['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $required = (bool) ($field['required'] ?? false);
            $exists = array_key_exists($handle, $data);
            if (!$exists) {
                if ($required) {
                    throw new \InvalidArgumentException('Обязательное поле отсутствует: ' . $handle);
                }
                $normalized[$handle] = null;
                continue;
            }

            $normalized[$handle] = $this->value($handle, (string) ($field['type'] ?? 'text'), $data[$handle], is_array($field['settings'] ?? null) ? $field['settings'] : []);
        }

        return $normalized;
    }

    /** @param mixed $value @param array<string, mixed> $settings */
    private function value(string $handle, string $type, mixed $value, array $settings): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'text', 'textarea', 'richtext' => $this->stringValue($handle, $value, $settings),
            'number' => $this->numberValue($handle, $value),
            'boolean' => $this->booleanValue($handle, $value),
            'date' => $this->dateValue($handle, $value),
            'datetime' => $this->datetimeValue($handle, $value),
            'media', 'relation' => $this->idOrListValue($handle, $value),
            'blocks' => $this->blocksValue($handle, $value),
            'json' => $value,
            default => throw new \InvalidArgumentException('Неподдерживаемый тип поля ' . $type . ' для ' . $handle),
        };
    }

    /** @param mixed $value @param array<string, mixed> $settings */
    private function stringValue(string $handle, mixed $value, array $settings): string
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть строкой.');
        }
        $text = trim((string) $value);
        $max = isset($settings['max_length']) ? (int) $settings['max_length'] : 0;
        if ($max > 0 && self::length($text) > $max) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' длиннее допустимого max_length.');
        }
        $min = isset($settings['min_length']) ? (int) $settings['min_length'] : 0;
        if ($min > 0 && self::length($text) < $min) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' короче допустимого min_length.');
        }
        return $text;
    }

    private function numberValue(string $handle, mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть числом.');
        }
        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    private function booleanValue(string $handle, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }
        throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть boolean.');
    }

    private function dateValue(string $handle, mixed $value): string
    {
        if (!is_scalar($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть датой YYYY-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if (!$date || $date->format('Y-m-d') !== (string) $value) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' содержит несуществующую дату.');
        }
        return (string) $value;
    }

    private function datetimeValue(string $handle, mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть date-time строкой.');
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть валидной date-time строкой.');
        }
    }

    private function idOrListValue(string $handle, mixed $value): string|array
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    throw new \InvalidArgumentException('Поле ' . $handle . ' должно содержать только строковые идентификаторы.');
                }
            }
            return array_values(array_map(static fn (mixed $item): string => trim((string) $item), $value));
        }
        throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть строкой или массивом строк.');
    }

    private function blocksValue(string $handle, mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Поле ' . $handle . ' должно быть массивом блоков.');
        }

        $definitions = [];
        foreach ((array) ($this->contentType['blocks'] ?? []) as $blockDefinition) {
            if (is_array($blockDefinition) && isset($blockDefinition['handle'])) {
                $definitions[(string) $blockDefinition['handle']] = $blockDefinition;
            }
        }

        $normalized = [];
        foreach ($value as $block) {
            if (!is_array($block) || !isset($block['handle']) || !is_array($block['data'] ?? null)) {
                throw new \InvalidArgumentException('Каждый блок поля ' . $handle . ' должен содержать handle и data.');
            }

            $blockHandle = (string) $block['handle'];
            if (!isset($definitions[$blockHandle])) {
                throw new \InvalidArgumentException('Блок ' . $blockHandle . ' не разрешён для типа контента.');
            }

            $blockType = [
                'fields' => is_array($definitions[$blockHandle]['fields'] ?? null) ? $definitions[$blockHandle]['fields'] : [],
                'blocks' => [],
            ];
            $normalized[] = [
                'handle' => $blockHandle,
                'data' => (new self($blockType))->data($block['data']),
            ];
        }
        return $normalized;
    }

    private static function slug(string $value): string
    {
        $slug = self::lower(trim($value));
        $slug = preg_replace('/[^a-z0-9а-яё_-]+/ui', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        return $slug !== '' ? $slug : 'entry';
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function locale(string $value): string
    {
        $locale = trim($value) ?: 'ru';
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException('locale должен быть в формате ru или ru-RU.');
        }
        return $locale;
    }
}
