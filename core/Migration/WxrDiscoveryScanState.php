<?php
declare(strict_types=1);

namespace CajeerEngine\Migration;

/** Mutable scanner state survives individual XMLReader::read() calls. */
final class WxrDiscoveryScanState
{
    /** @var array<string, int> */
    private array $counts = ['posts'=>0, 'pages'=>0, 'media'=>0, 'users'=>0, 'comments'=>0, 'categories'=>0, 'tags'=>0];
    /** @var array<string, int> */
    private array $types = [];
    /** @var array<string, int> */
    private array $statuses = [];
    /** @var array<string, string> */
    private array $fields = [];
    private ?string $version = null;
    private bool $rss = false;
    private bool $channel = false;
    private bool $item = false;
    private ?string $type = null;
    private ?string $status = null;
    private int $itemDepth = -1;

    public function accept(\XMLReader $reader): void
    {
        if ($reader->depth > 64 || $reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF) {
            throw new \InvalidArgumentException('Unsafe WXR XML.');
        }
        if ($reader->nodeType === \XMLReader::END_ELEMENT && $this->item && $reader->depth === $this->itemDepth && $reader->localName === 'item') {
            if ($this->type === null) {
                throw new \InvalidArgumentException('WXR item missing post type.');
            }
            $this->types[$this->type] = ($this->types[$this->type] ?? 0) + 1;
            if ($this->status !== null) {
                $this->statuses[$this->status] = ($this->statuses[$this->status] ?? 0) + 1;
            }
            $key = match ($this->type) {
                'post' => 'posts', 'page' => 'pages', 'attachment' => 'media', default => null,
            };
            if ($key !== null) {
                $this->counts[$key]++;
            }
            $this->item = false;
            $this->checkLimits();
            return;
        }
        if ($reader->nodeType !== \XMLReader::ELEMENT) {
            return;
        }

        $name = $reader->localName;
        $depth = $reader->depth;
        $wordpress = preg_match('~^https?://wordpress\.org/export/[0-9.]+/$~D', $reader->namespaceURI) === 1;
        if ($depth === 0 && $name === 'rss') {
            $this->rss = true;
        }
        if ($this->rss && $depth === 1 && $name === 'channel') {
            $this->channel = true;
        }
        if ($this->channel && $depth === 2 && $name === 'item' && $reader->namespaceURI === '') {
            if ($reader->isEmptyElement) {
                throw new \InvalidArgumentException('Empty WXR item.');
            }
            $this->item = true;
            $this->itemDepth = $depth;
            $this->type = null;
            $this->status = null;
            return;
        }
        if (!$wordpress || !$this->channel) {
            return;
        }
        if (!$this->item && $depth === 2) {
            if ($name === 'wxr_version') {
                $this->version = trim($reader->readString());
            } elseif ($name === 'author') {
                $this->counts['users']++;
            } elseif ($name === 'category') {
                $this->counts['categories']++;
            } elseif ($name === 'tag') {
                $this->counts['tags']++;
            }
        }
        if ($this->item && $depth === $this->itemDepth + 1) {
            if ($name === 'post_type') {
                $this->type = self::label($reader->readString());
            } elseif ($name === 'status') {
                $this->status = self::label($reader->readString());
            } elseif ($name === 'comment') {
                $this->counts['comments']++;
            }
        }
        if ($this->item && $depth === $this->itemDepth + 2 && $name === 'meta_key' && count($this->fields) < 101) {
            $value = trim($reader->readString());
            if (strlen($value) <= 200 && $value !== '') {
                $this->fields[$value] = $value;
            }
        }
        $this->checkLimits();
    }

    /** @return array<string, mixed> */
    public function report(#[\SensitiveParameter] string $xml): array
    {
        if (!$this->rss || !$this->channel || $this->version === null || !preg_match('/^1\.[012]$/D', $this->version) || $this->item) {
            throw new \InvalidArgumentException('Invalid or unsupported WordPress WXR (1.0–1.2 required).');
        }
        $warnings = ['WXR counts describe the export only. Author records are not a full user database; media entries do not contain file bytes. Custom types and plugin metadata require mapping.'];
        return SourceDiscovery::report('wordpress-wxr', $this->counts, $this->types, $this->statuses, array_values($this->fields), $warnings, [
            'version' => 'WXR ' . $this->version,
            'sha256' => hash('sha256', $xml),
            'bytes' => strlen($xml),
        ]);
    }

    private function checkLimits(): void
    {
        if (count($this->types) > 100 || count($this->statuses) > 100) {
            throw new \InvalidArgumentException('WXR contains too many distinct types or statuses.');
        }
    }

    private static function label(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value)) {
            throw new \InvalidArgumentException('Invalid WXR type or status.');
        }
        return $value;
    }
}
