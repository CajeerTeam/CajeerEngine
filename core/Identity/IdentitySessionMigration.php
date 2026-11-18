<?php

declare(strict_types=1);

namespace CajeerEngine\Identity;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/** Live, idempotent upgrade of pre-registry browser credentials (never API credentials). */
final readonly class IdentitySessionMigration
{
    public function __construct(private DatabaseManager $database, private int $idleMinutes = 720)
    {
        if ($idleMinutes < 1) throw new \InvalidArgumentException('Invalid session idle timeout.');
    }

    /** @return array<string,int> */
    public function status(): array
    {
        $this->requireSchema();
        $pdo = $this->database->connection();
        $base = " FROM ce_api_tokens t LEFT JOIN ce_user_sessions s ON s.token_id = t.id LEFT JOIN ce_users u ON u.id = t.user_id WHERE t.name = 'admin-session' AND t.revoked_at IS NULL AND s.token_id IS NULL";
        $missing = (int)$pdo->query('SELECT COUNT(*)'.$base)->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*)'.$base." AND u.status = 'active' AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP) AND COALESCE(t.last_used_at, t.created_at) >= :cutoff");
        $stmt->execute(['cutoff'=>$this->cutoff()]);
        $eligible = (int)$stmt->fetchColumn();
        $bad = (int)$pdo->query("SELECT COUNT(*) FROM ce_user_sessions s JOIN ce_api_tokens t ON t.id = s.token_id WHERE s.user_id <> t.user_id OR t.name <> 'admin-session'")->fetchColumn();
        $providers = [];
        $stmt = $pdo->query('SELECT provider, COUNT(*) AS amount FROM ce_user_sessions GROUP BY provider');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $providers[(string)$row['provider']] = (int)$row['amount'];
        return [
            'unregistered'=>$missing,
            'eligible_for_backfill'=>$eligible,
            'inactive_or_stale'=>$missing-$eligible,
            'inconsistent_registry'=>$bad,
            'registered_local'=>$providers['local']??0,
            'registered_skif4er'=>$providers['skif4er']??0,
            'registered_legacy'=>$providers['legacy']??0,
        ];
    }

    /**
     * Bounded/keyset migration. Repeat until remaining is zero; safe after interruption.
     * Never infer SSO from an email or even an identity link: old untagged tokens are `legacy`.
     * @return array<string,mixed>
     */
    public function migrate(int $batchSize = 100, int $maxBatches = 10): array
    {
        if ($batchSize < 1 || $batchSize > 500 || $maxBatches < 1 || $maxBatches > 200) {
            throw new \InvalidArgumentException('Use batch-size=1..500 and max-batches=1..200.');
        }
        $this->requireSchema();
        $pdo = $this->database->connection();
        $cursor = '';
        $migrated = $revoked = $raced = $scanned = $batches = 0;
        $cutoff = $this->cutoff();
        for ($batch=0; $batch<$maxBatches; $batch++) {
            $stmt = $pdo->prepare("SELECT t.id, t.user_id, t.created_at, t.last_used_at, t.expires_at, u.status
                FROM ce_api_tokens t LEFT JOIN ce_user_sessions s ON s.token_id = t.id
                LEFT JOIN ce_users u ON u.id = t.user_id
                WHERE t.name = 'admin-session' AND t.revoked_at IS NULL AND s.token_id IS NULL AND t.id > :cursor
                ORDER BY t.id ASC LIMIT :batch");
            $stmt->bindValue(':cursor',$cursor,PDO::PARAM_STR);
            $stmt->bindValue(':batch',$batchSize,PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) break;
            $batches++;
            foreach ($rows as $row) {
                $cursor = (string)$row['id'];
                $scanned++;
                $valid = ($row['status']??null) === 'active'
                    && ($row['expires_at'] === null || self::toEpoch((string)$row['expires_at']) > time())
                    && self::toEpoch((string)($row['last_used_at'] ?: $row['created_at'])) >= time()-$this->idleMinutes*60;
                // One transaction per row ensures an interrupted migration keeps earlier progress,
                // and a concurrent login/backfill can never turn a stale token into a new session.
                try { $outcome = $this->database->transaction(function (PDO $connection) use ($row,$valid,$cutoff): string {
                    if (!$valid) {
                        // Never revoke an admin token which a concurrent request just registered.
                        $expire = $connection->prepare("UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP
                            WHERE id = :id AND name = 'admin-session' AND revoked_at IS NULL
                            AND NOT EXISTS (SELECT 1 FROM ce_user_sessions WHERE token_id = :sid)");
                        $expire->execute(['id'=>$row['id'],'sid'=>$row['id']]);
                        return $expire->rowCount()===1?'revoked':'raced';
                    }
                    $insert = $connection->prepare("INSERT INTO ce_user_sessions
                        (token_id, user_id, provider, user_agent, ip_hint, issuer, oidc_sid_hash, created_at, last_seen_at, expires_at)
                        SELECT t.id, t.user_id, 'legacy', '', '', NULL, NULL, t.created_at,
                            COALESCE(t.last_used_at, t.created_at), t.expires_at
                        FROM ce_api_tokens t JOIN ce_users u ON u.id = t.user_id
                        WHERE t.id = :id AND t.name = 'admin-session' AND t.revoked_at IS NULL
                            AND u.status = 'active' AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)
                            AND COALESCE(t.last_used_at, t.created_at) >= :cutoff
                            AND NOT EXISTS (SELECT 1 FROM ce_user_sessions s WHERE s.token_id = t.id)");
                    $insert->execute(['id'=>$row['id'],'cutoff'=>$cutoff]);
                    return $insert->rowCount()===1?'migrated':'raced';
                }); } catch (\PDOException $e) {
                    // A concurrent browser request can create the row between selection and insert.
                    // Only a genuine uniqueness race for this exact token and owner is harmless.
                    $same = $pdo->prepare('SELECT user_id FROM ce_user_sessions WHERE token_id = :id');
                    $same->execute(['id'=>$row['id']]);
                    $owner = $same->fetchColumn();
                    if (!in_array((string)$e->getCode(),['23000','23505','19'],true)
                        || !is_string($owner) || $owner !== (string)$row['user_id']) throw $e;
                    $outcome='raced';
                }
                if ($outcome==='migrated') $migrated++;
                elseif ($outcome==='revoked') $revoked++;
                else $raced++;
            }
            if (count($rows)<$batchSize) break;
        }
        $after=$this->status();
        return ['ok'=>$after['inconsistent_registry']===0, 'migrated'=>$migrated,'revoked_stale'=>$revoked,
            'concurrent_or_skipped'=>$raced,'scanned'=>$scanned,'batches'=>$batches,
            'remaining'=>$after['unregistered'],'complete'=>$after['unregistered']===0,
            'status'=>$after];
    }

    public function requireSchema(): void
    {
        foreach (['ce_api_tokens','ce_users','ce_user_sessions','ce_oidc_identities','ce_oidc_transactions','ce_oidc_logout_replay'] as $table) {
            if (!$this->database->tableExists($table)) throw new \RuntimeException('Run database migrations before Identity acceptance: '.$table);
        }
        foreach (['token_id','provider','expires_at','revoked_at','oidc_sub_hash','assured_at','assurance_method','assurance_mfa'] as $column) {
            if (!$this->database->columnExists('ce_user_sessions',$column)) throw new \RuntimeException('Identity session schema requires migration 0030: '.$column);
        }
    }

    private function cutoff(): string { return gmdate('Y-m-d H:i:s',time()-$this->idleMinutes*60); }

    private static function toEpoch(string $value): int
    {
        if ($value==='') return 0;
        try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp(); }
        catch (\Throwable) { return 0; }
    }
}
