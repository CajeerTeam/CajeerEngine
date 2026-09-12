<?php

declare(strict_types=1);

namespace CajeerEngine\Search;

use PDO;

final readonly class PostgresFullTextSearchEngine implements SearchEngineInterface
{
    public function __construct(private PDO $pdo, private string $language = 'russian')
    {
    }

    public function search(string $query, ?string $index = null, int $limit = 20): array
    {
        $where = ['search_vector @@ plainto_tsquery(:language, :query)'];
        $params = ['language' => $this->language, 'query' => $query];
        if ($index !== null && $index !== '') {
            $where[] = 'index_name = :index';
            $params['index'] = $index;
        }
        $sql = 'SELECT index_name AS index, source_id AS id, source_id, title, excerpt, raw, ts_rank(search_vector, plainto_tsquery(:language, :query)) AS score, updated_at FROM ce_search_documents WHERE ' . implode(' AND ', $where) . ' ORDER BY score DESC, updated_at DESC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $row): array {
            $row['score'] = (float) ($row['score'] ?? 0);
            $decoded = json_decode((string) ($row['raw'] ?? '{}'), true);
            $row['raw'] = is_array($decoded) ? $decoded : [];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function index(string $index, string $id, array $document): void
    {
        $title = (string) ($document['title'] ?? '');
        $body = (string) ($document['body'] ?? '');
        $excerpt = mb_substr(strip_tags($body !== '' ? $body : $title), 0, 280);
        $stmt = $this->pdo->prepare('INSERT INTO ce_search_documents (index_name, source_id, title, excerpt, raw, search_vector, updated_at) VALUES (:index, :source_id, :title, :excerpt, :raw, to_tsvector(:language, :text), CURRENT_TIMESTAMP) ON CONFLICT (index_name, source_id) DO UPDATE SET title = EXCLUDED.title, excerpt = EXCLUDED.excerpt, raw = EXCLUDED.raw, search_vector = EXCLUDED.search_vector, updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            'index' => $index,
            'source_id' => $id,
            'title' => $title,
            'excerpt' => $excerpt,
            'raw' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'language' => $this->language,
            'text' => $title . ' ' . $body,
        ]);
    }

    public function delete(string $index, string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ce_search_documents WHERE index_name = :index AND source_id = :id');
        $stmt->execute(['index' => $index, 'id' => $id]);
    }

    public function diagnostics(): array
    {
        $rows = $this->pdo->query('SELECT index_name, COUNT(*) AS count FROM ce_search_documents GROUP BY index_name ORDER BY index_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row['index_name']] = (int) $row['count'];
        }
        return ['driver' => 'pgsql', 'language' => $this->language, 'indexes' => $indexes, 'documents' => array_sum($indexes)];
    }
}
