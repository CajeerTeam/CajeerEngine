<?php

declare(strict_types=1);

namespace CajeerEngine\Database\Repository;

use CajeerEngine\Database\DatabaseManager;
use PDO;
use Symfony\Component\HttpFoundation\Request;

/** Persisted, revocable device sessions backed by the existing opaque ce_api_tokens credentials. */
final readonly class UserSessionRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function database(): DatabaseManager { return $this->database; }

    /** @param array<string,mixed> $claims */
    public function register(string $tokenId, string $userId, string $provider, Request $request, ?string $expiresAt, array $claims = [], ?string $legacyCreated = null, ?string $legacySeen = null): void
    {
        if (!in_array($provider, ['local', 'skif4er', 'legacy'], true)) {
            throw new \InvalidArgumentException('Invalid session provider.');
        }
        $sid = $claims['sid'] ?? null;
        $sidHash = is_string($sid) && $sid !== '' ? hash('sha256', $sid) : null;
        if ($provider === 'skif4er' && is_string($claims['sid_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $claims['sid_hash'])) $sidHash = $claims['sid_hash'];
        $sub = $claims['sub'] ?? null;
        $subHash = $provider === 'skif4er' && is_string($sub) && $sub !== '' ? hash('sha256', $sub) : null;
        if ($provider === 'skif4er' && is_string($claims['sub_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $claims['sub_hash'])) $subHash = $claims['sub_hash'];
        $issuer = $provider === 'skif4er' ? 'https://id.skif4er.ru/realms/skif4er' : null;
        $userAgent = substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $request->headers->get('User-Agent', '')) ?? '', 0, 255);
        $ip = self::ipHint($request->getClientIp());
        $stmt = $this->database->connection()->prepare('INSERT INTO ce_user_sessions (token_id, user_id, provider, user_agent, ip_hint, issuer, oidc_sid_hash, oidc_sub_hash, expires_at, created_at, last_seen_at) VALUES (:token_id, :user_id, :provider, :user_agent, :ip_hint, :issuer, :oidc_sid_hash, :oidc_sub_hash, :expires_at, :created_at, :last_seen_at)');
        $stmt->execute([
            'token_id'=>$tokenId, 'user_id'=>$userId, 'provider'=>$provider,
            'user_agent'=>$userAgent, 'ip_hint'=>$ip, 'issuer'=>$issuer,
            'oidc_sid_hash'=>$sidHash, 'oidc_sub_hash'=>$subHash, 'expires_at'=>$expiresAt,
            'created_at'=>$legacyCreated ?? gmdate('Y-m-d H:i:s'),
            'last_seen_at'=>$legacySeen ?? gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Backfill existing 1.5.2 sessions when encountered, without resetting their token expiry. */
    public function validate(array $token, Request $request, int $idleMinutes = 720): bool
    {
        if (($token['name'] ?? '') !== 'admin-session' || !is_string($token['user_id'] ?? null)) return false;
        $id = (string) $token['id'];
        $row = $this->find($id);
        if ($row === null) {
            // After a verified cutover, an unknown browser token must not be silently recreated.
            // Leave disabled during rolling upgrades; enable only after identity:acceptance succeeds.
            if (filter_var(getenv('IDENTITY_SESSION_STRICT') ?: 'false', FILTER_VALIDATE_BOOLEAN)) return false;
            try { $this->register($id, (string)$token['user_id'], 'legacy', $request, $token['expires_at'] ?? null, [], (string)($token['created_at'] ?? ''), (string)($token['last_used_at'] ?? $token['created_at'] ?? '')); }
            catch (\PDOException $e) {
                // A different request may have backfilled this token concurrently.
                if ($this->find($id) === null) throw $e;
            }
            $row = $this->find($id);
        }
        if (!is_array($row) || !hash_equals((string)$row['user_id'], (string)$token['user_id']) || $row['revoked_at'] !== null) return false;
        $now = time();
        $idle = self::timestamp((string)$row['last_seen_at']);
        $expiry = $row['expires_at'] === null ? null : self::timestamp((string)$row['expires_at']);
        $expired = $row['expires_at'] !== null && ($expiry === null || $expiry <= $now);
        if ($expired || $idle === null || $idle + max(1, $idleMinutes)*60 < $now) {
            $this->revoke($id, (string)$token['user_id']);
            return false;
        }
        // Touch at most once a minute. Never resurrect a revoked session.
        if ($idle < $now - 60) {
            $stmt = $this->database->connection()->prepare('UPDATE ce_user_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE token_id = :id AND revoked_at IS NULL');
            $stmt->execute(['id'=>$id]);
            if ($stmt->rowCount() !== 1) return false;
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT token_id, user_id, provider, user_agent, ip_hint, issuer, oidc_sid_hash, oidc_sub_hash, created_at, last_seen_at, expires_at, revoked_at FROM ce_user_sessions WHERE token_id = :id');
        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> Public metadata; no token, IP address or raw OIDC subject. */
    public function activeForUser(string $userId, ?string $currentId = null, int $idleMinutes = 720, ?string $provider = null): array
    {
        if ($provider !== null && !in_array($provider, ['local', 'skif4er', 'legacy'], true)) throw new \InvalidArgumentException('Invalid session provider.');
        $sql = "SELECT s.token_id, s.provider, s.user_agent, s.ip_hint, s.created_at, s.last_seen_at, s.expires_at FROM ce_user_sessions s INNER JOIN ce_api_tokens t ON t.id = s.token_id AND t.user_id = s.user_id WHERE s.user_id = :uid AND s.revoked_at IS NULL AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP) AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP) AND s.last_seen_at >= :cutoff";
        $args = ['uid'=>$userId, 'cutoff'=>gmdate('Y-m-d H:i:s', time()-max(1,$idleMinutes)*60)];
        if ($provider !== null) { $sql .= ' AND s.provider = :provider'; $args['provider'] = $provider; }
        $stmt = $this->database->connection()->prepare($sql.' ORDER BY s.last_seen_at DESC LIMIT 100');
        $stmt->execute($args);
        return array_map(static fn(array $row):array => [
            'id'=>(string)$row['token_id'], 'provider'=>(string)$row['provider'],
            'device'=>(string)$row['user_agent'] ?: 'Unknown browser',
            'network'=>(string)$row['ip_hint'], 'created_at'=>(string)$row['created_at'],
            'last_seen_at'=>(string)$row['last_seen_at'], 'expires_at'=>$row['expires_at'],
            'current'=>hash_equals((string)$row['token_id'], (string)$currentId),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Revoke only an owned browser session, never an unrelated API token. */
    public function revoke(string $id, string $userId): bool
    {
        return $this->database->transaction(function (PDO $pdo) use ($id, $userId): bool {
            $stmt = $pdo->prepare("UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :uid AND name = 'admin-session' AND revoked_at IS NULL AND EXISTS (SELECT 1 FROM ce_user_sessions WHERE token_id = :id2 AND user_id = :uid2 AND revoked_at IS NULL)");
            $stmt->execute(['id'=>$id,'uid'=>$userId,'id2'=>$id,'uid2'=>$userId]);
            if ($stmt->rowCount() !== 1) return false;
            $stmt = $pdo->prepare('UPDATE ce_user_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE token_id = :id AND user_id = :uid AND revoked_at IS NULL');
            $stmt->execute(['id'=>$id,'uid'=>$userId]);
            return $stmt->rowCount() === 1;
        });
    }

    /** Revoke only browser sessions, retaining personal/API integration tokens. */
    public function revokeOthers(string $userId, ?string $exceptId): int
    {
        return $this->database->transaction(function (PDO $pdo) use ($userId,$exceptId): int {
            $sql = "SELECT token_id FROM ce_user_sessions WHERE user_id = :uid AND revoked_at IS NULL";
            $args = ['uid'=>$userId];
            if ($exceptId !== null) { $sql .= ' AND token_id <> :except'; $args['except']=$exceptId; }
            $stmt = $pdo->prepare($sql); $stmt->execute($args);
            $count = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) if ($this->revoke((string)$id,$userId)) $count++;
            return $count;
        });
    }

    /** Atomically apply a verified logout event and record its jti for replay defense. */
    public function applyBackchannelLogout(array $claims): array
    {
        $issuer = (string)$claims['iss']; $sub = $claims['sub'] ?? null; $sid = $claims['sid'] ?? null;
        $jtiHash = hash('sha256', $issuer."\0".(string)$claims['jti']);
        $subHash = is_string($sub) ? hash('sha256', $sub) : null;
        $sidHash = is_string($sid) ? hash('sha256', $sid) : null;
        return $this->database->transaction(function (PDO $pdo) use ($issuer, $sub, $sid, $subHash, $sidHash, $jtiHash, $claims): array {
            $pdo->prepare('DELETE FROM ce_oidc_logout_replay WHERE expires_at < :now')->execute(['now'=>time()]);
            $probe = $pdo->prepare('SELECT jti_hash FROM ce_oidc_logout_replay WHERE jti_hash = :jti');
            $probe->execute(['jti'=>$jtiHash]);
            if ($probe->fetchColumn() !== false) return ['already_processed'=>true, 'revoked_count'=>0];
            $insert = $pdo->prepare('INSERT INTO ce_oidc_logout_replay (jti_hash, issuer, expires_at) VALUES (:jti, :issuer, :exp)');
            $insert->execute(['jti'=>$jtiHash, 'issuer'=>$issuer, 'exp'=>(int)$claims['exp']]);
            // When both sid and sub are given they must describe the same local identity.
            // Existing pre-1.5.4 sessions may not yet have a subject digest; resolve their owner through the linked identity.
            $owner = null;
            if ($subHash !== null) {
                $ownerStmt = $pdo->prepare('SELECT user_id FROM ce_oidc_identities WHERE issuer = :issuer AND subject = :sub');
                $ownerStmt->execute(['issuer'=>$issuer, 'sub'=>$sub]);
                $owner = $ownerStmt->fetchColumn();
            }
            $conditions = ['s.provider = :provider', 's.issuer = :issuer', 's.revoked_at IS NULL', "t.name = 'admin-session'", 't.revoked_at IS NULL'];
            $args = ['provider'=>'skif4er', 'issuer'=>$issuer];
            if ($sidHash !== null) { $conditions[] = 's.oidc_sid_hash = :sid_hash'; $args['sid_hash']=$sidHash; }
            if ($subHash !== null) {
                if ($owner !== false && is_string($owner)) {
                    $conditions[] = "(s.oidc_sub_hash = :sub_hash OR (s.oidc_sub_hash IS NULL AND s.user_id = :owner))";
                    $args['sub_hash']=$subHash; $args['owner']=$owner;
                } else { $conditions[] = 's.oidc_sub_hash = :sub_hash'; $args['sub_hash']=$subHash; }
            }
            $find = $pdo->prepare('SELECT s.token_id, s.user_id FROM ce_user_sessions s INNER JOIN ce_api_tokens t ON t.id = s.token_id AND t.user_id = s.user_id WHERE '.implode(' AND ', $conditions));
            $find->execute($args);
            $count = 0;
            foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($this->revoke((string)$row['token_id'], (string)$row['user_id'])) $count++;
            }
            return ['already_processed'=>false, 'revoked_count'=>$count];
        });
    }

    /** Accept database timestamps with or without an explicit offset, interpreting naive dates as UTC. */
    private static function timestamp(string $value): ?int
    {
        try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp(); }
        catch (\Throwable) { return null; }
    }

    private static function ipHint(?string $ip): string
    {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip); return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0/24';
        }
        $packed = inet_pton($ip);
        return $packed === false ? '' : inet_ntop(substr($packed,0,6).str_repeat("\0",10)).'/48';
    }
}
