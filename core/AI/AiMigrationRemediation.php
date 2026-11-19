<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Audit\AuditLogger;
use CajeerEngine\Cms\RedirectRepository;
use CajeerEngine\Content\ContentEntryRepository;
use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Migration\{MigrationCenter, MigrationExecution};
use CajeerEngine\Public\{PublicPath, RouteIndex};
use PDO;

/** Confirmed, server-derived migration repairs. Never executes model-supplied code or URLs. */
final readonly class AiMigrationRemediation
{
    private const TTL = 3600;
    private const MAX = 100;

    public function __construct(
        private string $root,
        private DatabaseManager $db,
        private MigrationCenter $projects,
        private AuditLogger $audit,
    ) {}

    private function ready(string $projectId): array
    {
        MigrationExecution::validateId($projectId);
        if ((new ContentEntryRepository($this->root,$this->db))->storage() !== 'database')
            throw new \DomainException('Transactional migration remediation requires database-backed CMS content and routing.');
        foreach (['ce_ai_migration_remediations', 'ce_migration_seo_path_evidence', 'ce_public_routes', 'ce_public_route_state', 'ce_cms_redirects'] as $table) {
            if (!$this->db->tableExists($table)) throw new \DomainException('Run the SQL migrations before migration remediation.');
        }
        $project = $this->projects->show($projectId);
        if (($project['status'] ?? '') !== 'completed' && ($project['batch_execution']['state'] ?? '') !== 'completed') {
            throw new \DomainException('Migration import must be completed before remediation.');
        }
        return $project;
    }

    private function redirectCandidate(string $projectId, string $source, bool $indexVerified = false): array
    {
        if ($source === '' || strlen($source) > 700 || !str_starts_with($source, '/')) {
            throw new \InvalidArgumentException('Invalid legacy path.');
        }
        // Target path is derived exclusively from the operator-approved persisted SEO migration evidence.
        $query = $this->db->connection()->prepare('SELECT target_path FROM ce_migration_seo_path_evidence WHERE project_id = ? AND source_path = ?');
        $query->execute([$projectId, $source]);
        $target = $query->fetchColumn();
        if (!is_string($target)) throw new \DomainException('No approved migration mapping exists for this path.');
        if (PublicPath::content($source) !== $source || PublicPath::content($target) !== $target || $source === $target) {
            throw new \DomainException('Migration mapping is not safe to restore.');
        }
        $routes = new RouteIndex($this->root, $this->db);
        if (!$indexVerified && !($routes->check()['ok'] ?? false)) throw new \DomainException('Rebuild the inconsistent route index first.');
        if ($routes->lookup($source) !== null) throw new \DomainException('A public route already occupies the legacy path.');
        if (($routes->lookup($target)['kind'] ?? null) !== 'page') throw new \DomainException('Target page is not currently published.');
        if ((new RedirectRepository($this->root, $this->db))->byPath($source) !== null) {
            throw new \DomainException('Legacy redirect already exists; do not overwrite operator changes.');
        }
        return ['kind' => 'restore_redirect', 'source_path' => $source, 'target_path' => $target,
            'description' => 'Создать отсутствующий постоянный 301-редирект из подтверждённой карты миграции'];
    }

    private function routesCandidate(): array
    {
        if ((new RouteIndex($this->root, $this->db))->check()['ok'] ?? false) {
            throw new \DomainException('Public route index is already consistent.');
        }
        return ['kind' => 'rebuild_routes', 'source_path' => '', 'target_path' => '',
            'description' => 'Пересобрать публичный индекс маршрутов из действующих записей и редиректов'];
    }

    /** Read-only list; every entry is checked again at preparation AND at approval. */
    public function candidates(string $projectId, int $offset = 0): array
    {
        $this->ready($projectId);
        if ($offset < 0 || $offset > 1000000) throw new \InvalidArgumentException('Invalid remediation offset.');
        $options = []; $warnings = []; $next = null;
        $routes = new RouteIndex($this->root, $this->db);
        if (!($routes->check()['ok'] ?? false)) {
            $options[] = $this->routesCandidate();
            $warnings[] = 'Сначала восстановите индекс маршрутов; проверка редиректов временно заблокирована.';
        } else {
            $query = $this->db->connection()->prepare('SELECT source_path FROM ce_migration_seo_path_evidence WHERE project_id = ? ORDER BY source_path LIMIT '.(self::MAX + 1).' OFFSET '.$offset);
            $query->execute([$projectId]);
            $paths = $query->fetchAll(PDO::FETCH_COLUMN);
            if (count($paths) > self::MAX) { $next = $offset + self::MAX; $warnings[] = 'Показана ограниченная партия: доступны следующие 100 путей.'; }
            foreach (array_slice($paths, 0, self::MAX) as $path) {
                try { $options[] = $this->redirectCandidate($projectId, (string)$path, true); }
                catch (\DomainException|\InvalidArgumentException) { /* Conflicts are never auto-repaired. */ }
            }
        }
        return ['project_id' => $projectId, 'candidates' => $options, 'warnings' => $warnings, 'offset' => $offset, 'next_offset' => $next,
            'manual_only' => ['media_missing_or_corrupt','unverified_editorial_revision','missing_at_source',
                'legacy_query_redirect_broken','unresolved_legacy_media_url']];
    }

    public function prepare(string $projectId, string $userId, string $kind, string $path = ''): array
    {
        $this->ready($projectId);
        $candidate = match ($kind) {
            'restore_redirect' => $this->redirectCandidate($projectId, $path),
            'rebuild_routes' => $this->routesCandidate(),
            default => throw new \InvalidArgumentException('Unsupported remediation operation.'),
        };
        $id = self::uuid(); $now = time();
        $snapshot = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->transaction(function(PDO $pdo) use ($projectId, $userId, $kind, $id, $now, $snapshot): void {
            $query = $pdo->prepare("SELECT COUNT(*) FROM ce_ai_migration_remediations WHERE project_id = ? AND user_id = ? AND state = 'pending' AND expires_at > ?");
            $query->execute([$projectId, $userId, $now]);
            if ((int)$query->fetchColumn() >= 30) throw new \DomainException('Too many pending remediation proposals.');
            $query = $pdo->prepare('INSERT INTO ce_ai_migration_remediations (id, project_id, user_id, kind, snapshot_json, state, created_at, expires_at, decided_at, result_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL)');
            $query->execute([$id, $projectId, $userId, $kind, $snapshot, 'pending', $now, $now + self::TTL]);
        });
        $this->audit->record('ai.migration.remediation.prepared', $userId, ['project_id' => $projectId, 'proposal_id' => $id, 'kind' => $kind]);
        return $this->get($projectId, $userId, $id);
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private static function validateUuid(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) throw new \InvalidArgumentException('Invalid remediation ID.');
        return $id;
    }

    private static function map(array $row): array
    {
        return ['id' => $row['id'], 'project_id' => $row['project_id'], 'kind' => $row['kind'],
            'preview' => json_decode($row['snapshot_json'], true, 32, JSON_THROW_ON_ERROR),
            'state' => $row['state'], 'created_at' => (int)$row['created_at'], 'expires_at' => (int)$row['expires_at'],
            'decided_at' => $row['decided_at'] === null ? null : (int)$row['decided_at'],
            'result' => $row['result_json'] === null ? null : json_decode($row['result_json'], true, 32, JSON_THROW_ON_ERROR)];
    }

    public function pending(string $projectId, string $userId): array
    {
        $this->ready($projectId);
        $query = $this->db->connection()->prepare("SELECT * FROM ce_ai_migration_remediations WHERE project_id = ? AND user_id = ? AND state = 'pending' AND expires_at >= ? ORDER BY created_at DESC, id DESC LIMIT 30");
        $query->execute([$projectId, $userId, time()]);
        return array_map(self::map(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function history(string $projectId, string $userId): array
    {
        $this->ready($projectId);
        $query=$this->db->connection()->prepare("SELECT * FROM ce_ai_migration_remediations WHERE project_id = ? AND user_id = ? AND state IN ('applied','rolled_back','rejected') ORDER BY decided_at DESC, id DESC LIMIT 30");
        $query->execute([$projectId, $userId]);
        return array_map(self::map(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(string $projectId, string $userId, string $id): array
    {
        $query = $this->db->connection()->prepare('SELECT * FROM ce_ai_migration_remediations WHERE id = ? AND project_id = ? AND user_id = ?');
        $query->execute([self::validateUuid($id), $projectId, $userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \DomainException('Remediation proposal not found.');
        return self::map($row);
    }

    public function reject(string $projectId, string $userId, string $id): array
    {
        $this->ready($projectId);
        $proposal = $this->db->transaction(function(PDO $pdo) use ($projectId, $userId, $id): array {
            $query = $pdo->prepare("UPDATE ce_ai_migration_remediations SET state = 'rejected', decided_at = ? WHERE id = ? AND project_id = ? AND user_id = ? AND state = 'pending' AND expires_at >= ?");
            $query->execute([time(), self::validateUuid($id), $projectId, $userId, time()]);
            if ($query->rowCount() !== 1) throw new \DomainException('Remediation was already decided or expired.');
            return $this->get($projectId, $userId, $id);
        });
        $this->audit->record('ai.migration.remediation.rejected', $userId, ['project_id' => $projectId, 'proposal_id' => $id]);
        return $proposal;
    }

    /** Only exact, unused redirects created by this proposal can be rolled back. */
    public function rollback(string $projectId, string $userId, string $id): array
    {
        $this->ready($projectId);
        $proposal=(new RouteIndex($this->root, $this->db))->mutate(fn(): array => $this->db->transaction(function(PDO $pdo)use($projectId,$userId,$id):array {
            $row=$this->get($projectId,$userId,$id);
            if($row['state']!=='applied' || $row['kind']!=='restore_redirect')throw new \DomainException('Only an applied 301 remediation can be rolled back.');
            $claim=$pdo->prepare("UPDATE ce_ai_migration_remediations SET state = 'reverting' WHERE id = ? AND project_id = ? AND user_id = ? AND state = 'applied'");
            $claim->execute([self::validateUuid($id),$projectId,$userId]);
            if($claim->rowCount()!==1)throw new \DomainException('Remediation changed before rollback.');
            $expected=$row['result'];$redirects=new RedirectRepository($this->root,$this->db);
            $active=$redirects->byPath((string)($expected['source_path']??''));
            if(!is_array($active) || $active['id']!==($expected['redirect_id']??null) ||
                $active['target_url']!==($expected['target_path']??null) ||
                (int)$active['status_code']!==301 || !$active['enabled'] || (int)($active['hit_count']??0)!==0) {
                throw new \DomainException('Redirect was changed or already received traffic; manual rollback is required.');
            }
            if(!$redirects->delete($active['id']) || $redirects->byPath($expected['source_path'])!==null)
                throw new \RuntimeException('Redirect rollback failed.');
            $stmt=$pdo->prepare("UPDATE ce_ai_migration_remediations SET state = 'rolled_back', decided_at = ?, result_json = ? WHERE id = ? AND state = 'reverting'");
            $stmt->execute([time(),json_encode(['kind'=>'restore_redirect','rolled_back'=>true,'source_path'=>$expected['source_path']],JSON_THROW_ON_ERROR),$id]);
            if($stmt->rowCount()!==1)throw new \RuntimeException('Rollback result could not be saved.');
            return $this->get($projectId,$userId,$id);
        }));
        if(!((new RouteIndex($this->root,$this->db))->check()['ok']??false))throw new \RuntimeException('Recheck public routing after rollback.');
        $this->audit->record('ai.migration.remediation.rolled_back',$userId,['project_id'=>$projectId,'proposal_id'=>$id]);
        return $proposal;
    }

    /** Atomic one-time decision + repository write + postcondition verification. */
    public function approve(string $projectId, string $userId, string $id): array
    {
        $this->ready($projectId);
        $proposal = (new RouteIndex($this->root, $this->db))->mutate(fn(): array => $this->db->transaction(function(PDO $pdo) use ($projectId, $userId, $id): array {
            $proposal = $this->get($projectId, $userId, $id);
            if ($proposal['state'] !== 'pending' || $proposal['expires_at'] < time()) throw new \DomainException('Remediation was already decided or expired.');
            $claim = $pdo->prepare("UPDATE ce_ai_migration_remediations SET state = 'running' WHERE id = ? AND project_id = ? AND user_id = ? AND state = 'pending' AND expires_at >= ?");
            $claim->execute([self::validateUuid($id), $projectId, $userId, time()]);
            if ($claim->rowCount() !== 1) throw new \DomainException('Concurrent remediation already claimed this proposal.');
            $expected = $proposal['preview'];
            if ($proposal['kind'] === 'restore_redirect') {
                $fresh = $this->redirectCandidate($projectId, (string)$expected['source_path']);
                if ($fresh !== $expected) throw new \DomainException('Migration mapping changed. Prepare a new proposal.');
                $saved = (new RedirectRepository($this->root, $this->db))->save([
                    'source_path' => $fresh['source_path'], 'target_url' => $fresh['target_path'],
                    'status_code' => 301, 'enabled' => true,
                ]);
                if (($saved['source_path'] ?? '') !== $fresh['source_path'] ||
                    ($saved['target_url'] ?? '') !== $fresh['target_path'] || (int)($saved['status_code'] ?? 0) !== 301 || !$saved['enabled']) {
                    throw new \RuntimeException('Redirect verification failed.');
                }
                $result = ['kind' => 'restore_redirect', 'source_path' => $fresh['source_path'],
                    'target_path' => $fresh['target_path'], 'redirect_id' => $saved['id'], 'verified' => true];
            } elseif ($proposal['kind'] === 'rebuild_routes') {
                $fresh = $this->routesCandidate();
                if ($fresh !== $expected) throw new \DomainException('Routing state changed. Prepare a new proposal.');
                // The enclosing RouteIndex::mutate computes the new graph and commits it
                // atomically with this decision. Do not nest rebuild() under its write lock.
                $result = ['kind' => 'rebuild_routes', 'verified' => true];
            } else throw new \DomainException('Unsupported remediation action.');
            $query = $pdo->prepare("UPDATE ce_ai_migration_remediations SET state = 'applied', result_json = ?, decided_at = ? WHERE id = ? AND state = 'running'");
            $query->execute([json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), time(), $id]);
            if ($query->rowCount() !== 1) throw new \RuntimeException('Could not persist remediation result.');
            return $this->get($projectId, $userId, $id);
        }));
        if (!((new RouteIndex($this->root, $this->db))->check()['ok'] ?? false)) throw new \RuntimeException('Remediation completed but the public route index requires operator verification.');
        $this->audit->record('ai.migration.remediation.applied', $userId, ['project_id' => $projectId, 'proposal_id' => $id, 'kind' => $proposal['kind']]);
        return $proposal;
    }
}
