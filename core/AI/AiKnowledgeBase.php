<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Content\ContentTypeRepository;
use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Independently indexed editorial knowledge. Never writes to the public CMS search index. */
final readonly class AiKnowledgeBase
{
    private const MAX_TEXT = 131072;
    private const MAX_IMPORT = 2097152;

    public function __construct(
        private DatabaseManager $db,
        private ContentEntryRepository $entries,
        private ContentTypeRepository $types,
    ) {}

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private static function validId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[a-f0-9]{12}$/iD', $id))
            throw new \InvalidArgumentException('Invalid knowledge identifier.');
        return $id;
    }

    private static function text(mixed $value, int $max, bool $required = true): string
    {
        if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value))
            throw new \InvalidArgumentException('Invalid UTF-8 knowledge text.');
        $value = trim($value);
        if ($required && $value === '') throw new \InvalidArgumentException('Required knowledge field is empty.');
        return $value;
    }

    private static function locale(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $value))
            throw new \InvalidArgumentException('Invalid locale.');
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if (!in_array($value, ['public','internal'], true)) throw new \InvalidArgumentException('Invalid knowledge visibility.');
        return $value;
    }

    private static function status(mixed $value): string
    {
        if (!in_array($value, ['draft','published','archived'], true)) throw new \InvalidArgumentException('Invalid knowledge status.');
        return $value;
    }

    private static function slug(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $value))
            throw new \InvalidArgumentException('Slug requires 2–80 lowercase Latin letters, digits or hyphens.');
        return $value;
    }

    private static function categoriesQuery(PDO $pdo): array
    {
        return $pdo->query('SELECT id, slug, name, description, created_at, updated_at FROM ce_kb_categories ORDER BY name ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function categories(): array { return self::categoriesQuery($this->db->connection()); }

    public function saveCategory(array $input, ?string $id = null): array
    {
        self::keys($input, ['name','slug','description']);
        $name = self::text($input['name'] ?? null, 160);
        $slug = self::slug($input['slug'] ?? null);
        $description = self::text($input['description'] ?? '', 1500, false);
        return $this->db->transaction(function (PDO $pdo) use ($id, $name, $slug, $description): array {
            $now = time();
            if ($id === null) {
                $id = self::uuid();
                $pdo->prepare('INSERT INTO ce_kb_categories (id, slug, name, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$id, $slug, $name, $description, $now, $now]);
            } else {
                self::validId($id);
                $stmt = $pdo->prepare('UPDATE ce_kb_categories SET slug = ?, name = ?, description = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$slug, $name, $description, $now, $id]);
                if ($stmt->rowCount() === 0 && !self::exists($pdo, 'ce_kb_categories', $id)) throw new \DomainException('Category not found.');
            }
            return self::row($pdo, 'SELECT id, slug, name, description, created_at, updated_at FROM ce_kb_categories WHERE id = ?', [$id]);
        });
    }

    public function deleteCategory(string $id): void
    {
        self::validId($id);
        $this->db->transaction(static function (PDO $pdo) use ($id): void {
            $count = $pdo->prepare('SELECT COUNT(*) FROM ce_kb_documents WHERE category_id = ?');
            $count->execute([$id]);
            if ((int)$count->fetchColumn() !== 0) throw new \DomainException('Move or delete category documents first.');
            $stmt = $pdo->prepare('DELETE FROM ce_kb_categories WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) throw new \DomainException('Category not found.');
        });
    }

    private static function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) throw new \InvalidArgumentException('Unknown knowledge field.');
    }

    private static function row(PDO $pdo, string $query, array $args): array
    {
        $stmt = $pdo->prepare($query); $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \DomainException('Knowledge record not found.');
        return $row;
    }

    private static function exists(PDO $pdo, string $table, string $id): bool
    {
        // Table is a fixed internal constant, not user input.
        $stmt = $pdo->prepare('SELECT 1 FROM '.$table.' WHERE id = ?');
        $stmt->execute([$id]); return (bool)$stmt->fetchColumn();
    }

    private static function category(PDO $pdo, mixed $id): ?string
    {
        if ($id === null || $id === '') return null;
        if (!is_string($id)) throw new \InvalidArgumentException('Invalid category.');
        self::validId($id);
        if (!self::exists($pdo, 'ce_kb_categories', $id)) throw new \DomainException('Category not found.');
        return $id;
    }

    private static function documentRow(PDO $pdo, string $id): array
    {
        return self::row($pdo, 'SELECT * FROM ce_kb_documents WHERE id = ?', [self::validId($id)]);
    }

    public function document(string $id): array { return self::documentRow($this->db->connection(), $id); }

    /** @return array{items:array,total:int,limit:int,offset:int} */
    public function documents(int $limit = 30, int $offset = 0, string $categoryId = '', string $query = ''): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) throw new \InvalidArgumentException('Invalid pagination.');
        $query = self::text($query, 120, false);
        $conditions = []; $params = [];
        if ($categoryId !== '') { $conditions[] = 'category_id = ?'; $params[] = self::validId($categoryId); }
        if ($query !== '') { $conditions[] = "(title LIKE ? ESCAPE '!' OR slug LIKE ? ESCAPE '!')"; $params[] = '%'.self::escapeLike($query).'%'; $params[] = '%'.self::escapeLike($query).'%'; }
        $where = $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';
        $pdo = $this->db->connection();
        $total = $pdo->prepare('SELECT COUNT(*) FROM ce_kb_documents'.$where); $total->execute($params);
        $stmt = $pdo->prepare('SELECT id, category_id, slug, title, locale, visibility, status, source_kind, source_type, source_id, source_revision, revision, indexed_revision, updated_at, created_at FROM ce_kb_documents'.$where.' ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?');
        $i = 0; foreach ($params as $p) $stmt->bindValue(++$i, $p);
        $stmt->bindValue(++$i, $limit, PDO::PARAM_INT); $stmt->bindValue(++$i, $offset, PDO::PARAM_INT); $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$total->fetchColumn(), 'limit' => $limit, 'offset' => $offset];
    }

    private static function escapeLike(string $term): string
    {
        // Query is escaped for SQL LIKE; input is still bound, never interpolated.
        return str_replace(['!','%','_'], ['!!','!%','!_'], $term);
    }

    public function saveDocument(array $input, ?string $id = null): array
    {
        self::keys($input, ['title','slug','body','locale','visibility','status','category_id','expected_revision']);
        $title = self::text($input['title'] ?? null, 240);
        $slug = self::slug($input['slug'] ?? null);
        $body = self::text($input['body'] ?? null, self::MAX_TEXT);
        $locale = self::locale($input['locale'] ?? 'ru');
        $visibility = self::scope($input['visibility'] ?? 'internal');
        $status = self::status($input['status'] ?? 'draft');
        if ($id !== null && (!is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 1))
            throw new \InvalidArgumentException('Expected revision is required for updates.');
        return $this->db->transaction(function (PDO $pdo) use ($id, $input, $title, $slug, $body, $locale, $visibility, $status): array {
            $category = self::category($pdo, $input['category_id'] ?? null);
            $now = time();
            if ($id === null) {
                $id = self::uuid();
                $pdo->prepare('INSERT INTO ce_kb_documents (id, category_id, slug, title, body, locale, visibility, status, source_kind, source_type, source_id, source_revision, revision, indexed_revision, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, 1, 0, ?, ?)')
                    ->execute([$id, $category, $slug, $title, $body, $locale, $visibility, $status, 'manual', $now, $now]);
            } else {
                $old = self::documentRow($pdo, $id);
                if ($old['source_kind'] === 'cms' && ($title !== $old['title'] || $slug !== $old['slug'] || $body !== $old['body'] || $locale !== $old['locale']))
                    throw new \DomainException('CMS-synced content is read-only; only category, publication and visibility may change.');
                $stmt = $pdo->prepare('UPDATE ce_kb_documents SET category_id = ?, slug = ?, title = ?, body = ?, locale = ?, visibility = ?, status = ?, revision = revision + 1, updated_at = ? WHERE id = ? AND revision = ?');
                $stmt->execute([$category, $slug, $title, $body, $locale, $visibility, $status, $now, $id, $input['expected_revision']]);
                if ($stmt->rowCount() !== 1) throw new \DomainException('Knowledge revision conflict; reload before saving.');
            }
            self::reindexDocument($pdo, self::documentRow($pdo, $id));
            return self::documentRow($pdo, $id);
        });
    }

    public function deleteDocument(string $id, int $expectedRevision): void
    {
        if ($expectedRevision < 1) throw new \InvalidArgumentException('Expected revision required.');
        $this->db->transaction(static function (PDO $pdo) use ($id, $expectedRevision): void {
            $doc = self::documentRow($pdo, $id);
            if ((int)$doc['revision'] !== $expectedRevision) throw new \DomainException('Knowledge revision conflict; reload before deleting.');
            $pdo->prepare('DELETE FROM ce_kb_terms WHERE document_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM ce_kb_chunks WHERE document_id = ?')->execute([$id]);
            $stmt = $pdo->prepare('DELETE FROM ce_kb_documents WHERE id = ? AND revision = ?');
            $stmt->execute([$id, $expectedRevision]);
            if ($stmt->rowCount() !== 1) throw new \DomainException('Knowledge revision conflict.');
        });
    }

    /** Direct upload with strict size/magic checks; stores only extracted text, never original binaries. */
    public function importFile(array $input): array
    {
        self::keys($input, ['filename','base64','title','slug','locale','visibility','status','category_id']);
        $name = self::text($input['filename'] ?? null, 255);
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) throw new \InvalidArgumentException('Invalid filename.');
        if (!is_string($input['base64'] ?? null) || strlen($input['base64']) > (int)(self::MAX_IMPORT * 4 / 3) + 16)
            throw new \InvalidArgumentException('File too large.');
        $bytes = base64_decode($input['base64'], true);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > self::MAX_IMPORT)
            throw new \InvalidArgumentException('Invalid or oversized file.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, ['txt', 'md', 'markdown'], true)) {
            if (str_contains($bytes, "\0") || !preg_match('//u', $bytes)) throw new \InvalidArgumentException('Invalid text encoding.');
            $body = $bytes;
        } elseif ($extension === 'pdf') {
            $body = self::extractPdf($bytes);
        } else {
            throw new \InvalidArgumentException('Supported documents: TXT, Markdown and searchable PDF.');
        }
        $body = self::text($body, self::MAX_TEXT);
        $title = $input['title'] ?? pathinfo($name, PATHINFO_FILENAME);
        $slug = $input['slug'] ?? self::makeSlug($name);
        return $this->db->transaction(function (PDO $pdo) use ($input, $body, $title, $slug): array {
            $category = self::category($pdo, $input['category_id'] ?? null);
            $now = time(); $id = self::uuid();
            $pdo->prepare('INSERT INTO ce_kb_documents (id, category_id, slug, title, body, locale, visibility, status, source_kind, source_type, source_id, source_revision, revision, indexed_revision, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, 1, 0, ?, ?)')
                ->execute([$id, $category, self::slug($slug), self::text($title, 240), $body,
                    self::locale($input['locale'] ?? 'ru'), self::scope($input['visibility'] ?? 'internal'), self::status($input['status'] ?? 'draft'), 'file', $now, $now]);
            self::reindexDocument($pdo, self::documentRow($pdo, $id));
            return self::documentRow($pdo, $id);
        });
    }

    private static function makeSlug(string $name): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME))), '-');
        return strlen($slug) >= 2 ? substr($slug, 0, 78) : 'document-'.bin2hex(random_bytes(4));
    }

    private static function extractPdf(string $bytes): string
    {
        if (!str_starts_with($bytes, '%PDF-') || !function_exists('proc_open'))
            throw new \InvalidArgumentException('Not a supported searchable PDF.');
        $bin = '/usr/bin/pdftotext';
        if (!is_executable($bin)) throw new \DomainException('PDF import requires Poppler pdftotext on the server.');
        $path = tempnam(sys_get_temp_dir(), 'ce-kb-');
        if ($path === false) throw new \RuntimeException('Unable to create temporary import file.');
        $handle = false;
        try {
            @chmod($path, 0600);
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('Temporary PDF write failed.');
            $handle = proc_open([$bin, '-f', '1', '-l', '100', '-nopgbrk', '-enc', 'UTF-8', $path, '-'],
                [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, ['PATH'=>'/usr/bin:/bin']);
            if (!is_resource($handle)) throw new \RuntimeException('PDF extractor could not start.');
            fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $text = ''; $stderr = ''; $start = microtime(true);
            while (true) {
                $status = proc_get_status($handle);
                foreach ([1,2] as $number) {
                    $chunk = stream_get_contents($pipes[$number]);
                    if ($number === 1) $text .= $chunk; else $stderr .= substr($chunk, 0, 128);
                }
                if (strlen($text) > self::MAX_TEXT || microtime(true) - $start > 10) {
                    proc_terminate($handle, 9); throw new \DomainException('PDF exceeds text limit or extraction timeout.');
                }
                if (!$status['running']) {
                    if ($status['exitcode'] !== 0) throw new \InvalidArgumentException('PDF text extraction failed.');
                    break;
                }
                usleep(20000);
            }
            return $text;
        } finally {
            if (isset($pipes)) foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($handle)) proc_close($handle);
            @unlink($path);
        }
    }

    /** Sync only a published CMS entry; no private/draft CMS data enters the knowledge index. */
    public function syncCms(string $type, string $id): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $type)) throw new \InvalidArgumentException('Invalid content type.');
        self::validId($id);
        $entry = $this->entries->findById($type, $id);
        if ($entry === null || ($entry['status'] ?? '') !== 'published') throw new \DomainException('Only published CMS entries can be imported.');
        $schema = $this->types->find($type);
        if (!is_array($schema)) throw new \DomainException('Content type not found.');
        $parts = [];
        foreach ((array)($schema['fields'] ?? []) as $field) {
            $handle = (string)($field['handle'] ?? '');
            $kind = (string)($field['type'] ?? '');
            $value = $entry['data'][$handle] ?? null;
            if (in_array($kind, ['text','textarea','richtext'], true) && is_string($value)) $parts[] = strip_tags($value);
            if ($kind === 'blocks' && is_array($value)) {
                foreach ($value as $block) {
                    if (!is_array($block) || !is_array($block['data'] ?? null)) continue;
                    foreach ((array)($schema['blocks'] ?? []) as $def) {
                        if (($def['handle'] ?? null) !== ($block['handle'] ?? null)) continue;
                        foreach ((array)($def['fields'] ?? []) as $sub) {
                            if (in_array(($sub['type'] ?? ''), ['text','textarea','richtext'], true) && is_string($block['data'][$sub['handle']] ?? null))
                                $parts[] = strip_tags($block['data'][$sub['handle']]);
                        }
                    }
                }
            }
        }
        $body = self::text(implode("\n\n", $parts), self::MAX_TEXT);
        $title = self::text($entry['title'], 240);
        $slug = self::makeSlug($type.'-'.$entry['slug']);
        $locale = self::locale($entry['locale']);
        return $this->db->transaction(function (PDO $pdo) use ($id, $type, $entry, $body, $title, $slug, $locale): array {
            $stmt = $pdo->prepare('SELECT id, category_id, status, revision FROM ce_kb_documents WHERE source_kind = ? AND source_type = ? AND source_id = ?');
            $stmt->execute(['cms', $type, $id]); $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            $now = time();
            if ($existing) {
                $docId = $existing['id'];
                $pdo->prepare('UPDATE ce_kb_documents SET title = ?, body = ?, locale = ?, source_revision = ?, revision = revision + 1, updated_at = ? WHERE id = ?')
                    ->execute([$title, $body, $locale, (int)$entry['revision'], $now, $docId]);
            } else {
                $docId = self::uuid();
                $pdo->prepare('INSERT INTO ce_kb_documents (id, category_id, slug, title, body, locale, visibility, status, source_kind, source_type, source_id, source_revision, revision, indexed_revision, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)')
                    ->execute([$docId, $slug, $title, $body, $locale, 'internal', 'published', 'cms', $type, $id, (int)$entry['revision'], $now, $now]);
            }
            self::reindexDocument($pdo, self::documentRow($pdo, $docId));
            return self::documentRow($pdo, $docId);
        });
    }

    /** Public and internal documents share a private index, with explicit visibility selection at search. */
    public function search(string $query, string $visibility = 'all', string $locale = '', int $limit = 20): array
    {
        $query = self::text($query, 200);
        if (!in_array($visibility, ['all','public','internal'], true) || $limit < 1 || $limit > 50)
            throw new \InvalidArgumentException('Invalid knowledge search filter.');
        if ($locale !== '') self::locale($locale);
        $terms = array_slice(array_keys(self::terms($query)), 0, 8);
        if ($terms === []) return [];
        $pdo = $this->db->connection(); $scores = []; $chunks = [];
        $sql = 'SELECT t.document_id, t.chunk_no, t.frequency, d.title, d.locale, d.visibility, d.revision, c.content FROM ce_kb_terms t JOIN ce_kb_documents d ON d.id = t.document_id JOIN ce_kb_chunks c ON c.document_id = t.document_id AND c.chunk_no = t.chunk_no WHERE t.term = ? AND d.status = ? AND d.indexed_revision = d.revision';
        if ($visibility !== 'all') $sql .= ' AND d.visibility = ?';
        if ($locale !== '') $sql .= ' AND d.locale = ?';
        $sql .= ' ORDER BY d.updated_at DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        foreach ($terms as $term) {
            $params = [$term, 'published'];
            if ($visibility !== 'all') $params[] = $visibility;
            if ($locale !== '') $params[] = $locale;
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (string)$row['document_id'];
                $scores[$id] = ($scores[$id] ?? 0) + min(8, (int)$row['frequency']);
                if (!isset($chunks[$id]) || (int)$row['chunk_no'] < (int)$chunks[$id]['chunk_no']) $chunks[$id] = $row;
            }
            $stmt->closeCursor();
        }
        arsort($scores, SORT_NUMERIC);
        $results = [];
        foreach (array_slice(array_keys($scores), 0, 200) as $id) {
            $row = $chunks[$id];
            // A CMS publication can change without a knowledge sync. Verify its live revision
            // before returning cached text to any caller, preventing stale publication leaks.
            $stmt = $pdo->prepare('SELECT source_kind, source_type, source_id, source_revision FROM ce_kb_documents WHERE id = ?');
            $stmt->execute([$id]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($source && $source['source_kind'] === 'cms') {
                $current = $this->entries->findById((string)$source['source_type'], (string)$source['source_id']);
                if ($current === null || $current['status'] !== 'published' || (int)$current['revision'] !== (int)$source['source_revision']) continue;
            }

            $results[] = ['id' => $id, 'title' => $row['title'], 'locale' => $row['locale'],
                'visibility' => $row['visibility'], 'revision' => (int)$row['revision'],
                'score' => $scores[$id], 'excerpt' => self::utf8Excerpt((string)$row['content'])];
            if (count($results) >= $limit) break;
        }
        return $results;
    }

    /**
     * Ranked, bounded source chunks for server-side grounded answers. Only current,
     * published, indexed documents are eligible. The exact CMS revision is checked
     * again before every source leaves this service.
     * @return list<array{id:string,title:string,locale:string,visibility:string,revision:int,chunk_no:int,content:string,score:int}>
     */
    public function retrieve(string $query, string $visibility = 'all', string $locale = '', int $limit = 6): array
    {
        $query = self::text($query, 1200);
        if (!in_array($visibility, ['all','public'], true) || $limit < 1 || $limit > 8)
            throw new \InvalidArgumentException('Invalid retrieval scope.');
        if ($locale !== '') self::locale($locale);
        $terms = array_slice(array_keys(self::terms($query)), 0, 14);
        if ($terms === []) return [];
        $pdo = $this->db->connection();
        // One round trip, retaining the existing 350-row limit PER TERM.
        // A global LIMIT (or a window over all matching chunks) can either
        // drop uncommon terms or scan millions of rows before discarding them.
        // Each indexed term receives its own bounded, ordered subquery; only
        // the bounded result is joined to chunk bodies and scored in PHP.
        $selects = []; $params = [];
        foreach ($terms as $i => $term) {
            $sql = 'SELECT t.term, t.document_id, t.chunk_no, t.frequency, d.title, d.locale, d.visibility, d.revision, d.source_kind, d.source_type, d.source_id, d.source_revision'
                .' FROM ce_kb_terms t JOIN ce_kb_documents d ON d.id = t.document_id'
                .' WHERE t.term = ? AND d.status = ? AND d.indexed_revision = d.revision';
            $params[] = $term; $params[] = 'published';
            if ($visibility === 'public') { $sql .= ' AND d.visibility = ?'; $params[] = 'public'; }
            if ($locale !== '') { $sql .= ' AND d.locale = ?'; $params[] = $locale; }
            // Each derived table has its own LIMIT, portable to PostgreSQL,
            // MySQL/MariaDB and SQLite without rewriting published migrations.
            $selects[] = 'SELECT * FROM ('.$sql.' ORDER BY d.updated_at DESC, t.document_id, t.chunk_no LIMIT 350) AS term_'.$i;
        }
        $stmt = $pdo->prepare('SELECT ranked.*, c.content FROM ('.implode(' UNION ALL ', $selects)
            .') AS ranked JOIN ce_kb_chunks c ON c.document_id = ranked.document_id AND c.chunk_no = ranked.chunk_no');
        $stmt->execute($params);
        $candidates = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['document_id'].':'.$row['chunk_no'];
            if (!isset($candidates[$key])) $candidates[$key] = ['row' => $row, 'score' => 0, 'matches' => 0];
            $candidates[$key]['score'] += min(12, (int)$row['frequency']);
            $candidates[$key]['matches']++;
        }
        $stmt->closeCursor();
        uasort($candidates, static fn (array $a, array $b): int => ($b['matches'] * 4 + $b['score']) <=> ($a['matches'] * 4 + $a['score']));
        $out = []; $perDoc = []; $checked = [];
        foreach ($candidates as $item) {
            if (count($out) >= $limit) break;
            $row = $item['row']; $id = (string)$row['document_id'];
            if (($perDoc[$id] ?? 0) >= 2) continue;
            if (!array_key_exists($id, $checked)) {
                $checked[$id] = true;
                if ($row['source_kind'] === 'cms') {
                    // Refuse stale, unpublished and deleted source revisions, even if index is not refreshed.
                    $current = $this->entries->findById((string)$row['source_type'], (string)$row['source_id']);
                    $checked[$id] = $current !== null && ($current['status'] ?? '') === 'published'
                        && (int)$current['revision'] === (int)$row['source_revision'];
                }
            }
            if (!$checked[$id]) continue;
            $out[] = ['id' => $id, 'title' => (string)$row['title'], 'locale' => (string)$row['locale'],
                'visibility' => (string)$row['visibility'], 'revision' => (int)$row['revision'],
                'chunk_no' => (int)$row['chunk_no'], 'content' => (string)$row['content'],
                'score' => $item['matches'] * 4 + $item['score']];
            $perDoc[$id] = ($perDoc[$id] ?? 0) + 1;
        }
        return $out;
    }

    /** Returns false if a previously cited source has been edited, unpublished or removed. */
    public function isCurrentSource(string $id, int $revision, string $scope): bool
    {
        if ($revision < 1 || !in_array($scope, ['all', 'public'], true)) return false;
        $stmt = $this->db->connection()->prepare('SELECT revision, indexed_revision, status, visibility, source_kind, source_type, source_id, source_revision FROM ce_kb_documents WHERE id = ?');
        $stmt->execute([self::validId($id)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status'] !== 'published' || (int)$row['revision'] !== $revision
            || (int)$row['indexed_revision'] !== $revision || ($scope === 'public' && $row['visibility'] !== 'public')) return false;
        if ($row['source_kind'] === 'cms') {
            $current = $this->entries->findById((string)$row['source_type'], (string)$row['source_id']);
            return $current !== null && ($current['status'] ?? '') === 'published'
                && (int)$current['revision'] === (int)$row['source_revision'];
        }
        return true;
    }

    /** Rebuilds corrupted/old derived indexes in bounded batches; repeats are safe. */
    public function reindex(int $limit = 20, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) throw new \InvalidArgumentException('Invalid reindex paging.');
        return $this->db->transaction(static function (PDO $pdo) use ($limit, $offset): array {
            $stmt = $pdo->prepare('SELECT * FROM ce_kb_documents ORDER BY id ASC LIMIT ? OFFSET ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT); $stmt->bindValue(2, $offset, PDO::PARAM_INT); $stmt->execute();
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC); $stmt->closeCursor();
            $count = 0;
            foreach ($documents as $doc) { self::reindexDocument($pdo, $doc); ++$count; }
            return ['processed' => $count, 'limit' => $limit, 'next_offset' => $count < $limit ? null : $offset + $count];
        });
    }

    private static function utf8Excerpt(string $value): string
    {
        return preg_match('/^.{0,500}/us', $value, $match) ? $match[0] : '';
    }

    /** @return array<string,int> Bounded Unicode token frequencies (works without mbstring). */
    public static function terms(string $value): array
    {
        if (!preg_match('//u', $value)) throw new \InvalidArgumentException('Invalid UTF-8.');
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8')
            : strtr(strtolower($value), array_combine(preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЫЬЪЭЮЯ', -1, PREG_SPLIT_NO_EMPTY),
                preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщыьъэюя', -1, PREG_SPLIT_NO_EMPTY)));
        preg_match_all('/[\p{L}\p{N}]{2,48}/u', $value, $matches);
        $result = [];
        foreach (array_slice($matches[0], 0, 8000) as $word) {
            $result[$word] = min(15, ($result[$word] ?? 0) + 1);
            if (count($result) >= 3000) break;
        }
        return $result;
    }

    /** Pure deterministic chunker; used for both publishing and regression tests. */
    public static function chunks(string $text, int $maxBytes = 900): array
    {
        if ($maxBytes < 100 || $maxBytes > 4000 || !preg_match('//u', $text)) throw new \InvalidArgumentException('Invalid chunk input.');
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) throw new \InvalidArgumentException('Invalid chunk encoding.');
        $chunks = []; $current = '';
        foreach ($words as $word) {
            // Keep a single long word from creating an oversized database row.
            if (strlen($word) > $maxBytes) {
                if ($current !== '') { $chunks[] = $current; $current = ''; }
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    if (strlen($current) + strlen($character) > $maxBytes) { $chunks[] = $current; $current = ''; }
                    $current .= $character;
                }
                continue;
            }
            if ($current !== '' && strlen($current) + strlen($word) + 1 > $maxBytes) { $chunks[] = $current; $current = ''; }
            $current .= ($current === '' ? '' : ' ') . $word;
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    private static function reindexDocument(PDO $pdo, array $doc): void
    {
        $id = $doc['id'];
        $pdo->prepare('DELETE FROM ce_kb_terms WHERE document_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ce_kb_chunks WHERE document_id = ?')->execute([$id]);
        if ($doc['status'] === 'published') {
            $chunks = array_slice(self::chunks($doc['title']."\n".$doc['body']), 0, 220);
            $addChunk = $pdo->prepare('INSERT INTO ce_kb_chunks (document_id, chunk_no, content) VALUES (?, ?, ?)');
            $addTerm = $pdo->prepare('INSERT INTO ce_kb_terms (term, document_id, chunk_no, frequency) VALUES (?, ?, ?, ?)');
            foreach ($chunks as $i => $chunk) {
                $addChunk->execute([$id, $i, $chunk]);
                foreach (self::terms($chunk) as $term => $freq) $addTerm->execute([$term, $id, $i, $freq]);
            }
        }
        $pdo->prepare('UPDATE ce_kb_documents SET indexed_revision = revision WHERE id = ?')->execute([$id]);
    }
}
