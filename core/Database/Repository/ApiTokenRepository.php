<?php

declare(strict_types=1);

namespace CajeerEngine\Database\Repository;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Support\Uuid;

final readonly class ApiTokenRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /** @param list<string> $scopes @return array{plain_token:string,id:string,prefix:string,expires_at:?string} */
    public function create(?string $userId, string $name, array $scopes, ?\DateTimeImmutable $expiresAt = null): array
    {
        $plain = 'ce_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $id = Uuid::v4();
        $prefix = substr($plain, 0, 10);
        $scopes = $this->normalizeScopes($scopes);
        $scopesJson = json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expires = $expiresAt?->format('Y-m-d H:i:s');
        $driver = $this->database->driver();
        $sql = $driver === 'pgsql'
            ? 'INSERT INTO ce_api_tokens (id, user_id, name, token_hash, token_prefix, scopes, expires_at, created_at) VALUES (:id, :user_id, :name, :token_hash, :token_prefix, CAST(:scopes AS jsonb), :expires_at, CURRENT_TIMESTAMP)'
            : 'INSERT INTO ce_api_tokens (id, user_id, name, token_hash, token_prefix, scopes, expires_at, created_at) VALUES (:id, :user_id, :name, :token_hash, :token_prefix, :scopes, :expires_at, CURRENT_TIMESTAMP)';
        $stmt = $this->database->connection()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $hash,
            'token_prefix' => $prefix,
            'scopes' => $scopesJson,
            'expires_at' => $expires,
        ]);

        return ['plain_token' => $plain, 'id' => $id, 'prefix' => $prefix, 'expires_at' => $expires];
    }

    public function count(): int
    {
        return (int) $this->database->connection()->query('SELECT COUNT(*) FROM ce_api_tokens')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function all(?string $userId = null): array
    {
        $sql = 'SELECT id, user_id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at FROM ce_api_tokens';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->database->connection()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->normalize($row), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->database->connection()->prepare('SELECT id, user_id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at FROM ce_api_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->normalize($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findValidPlainToken(string $plain): ?array
    {
        if (!str_starts_with($plain, 'ce_')) {
            return null;
        }
        $hash = hash('sha256', $plain);
        $stmt = $this->database->connection()->prepare('SELECT id, user_id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at FROM ce_api_tokens WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        if (!empty($row['revoked_at'])) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) !== false && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return $this->normalize($row);
    }

    public function touch(string $id): void
    {
        $stmt = $this->database->connection()->prepare('UPDATE ce_api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function revoke(string $id): bool
    {
        $stmt = $this->database->connection()->prepare('UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function revokeForUser(string $userId): int
    {
        $stmt = $this->database->connection()->prepare('UPDATE ce_api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND revoked_at IS NULL');
        $stmt->execute(['user_id' => $userId]);
        return $stmt->rowCount();
    }

    /** @return array<string, mixed> */
    private function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'user_id' => isset($row['user_id']) ? (string) $row['user_id'] : null,
            'name' => (string) $row['name'],
            'token_prefix' => (string) ($row['token_prefix'] ?? ''),
            'scopes' => $this->decodeList((string) $row['scopes']),
            'last_used_at' => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            'expires_at' => isset($row['expires_at']) ? (string) $row['expires_at'] : null,
            'revoked_at' => isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param list<string> $scopes @return list<string> */
    private function normalizeScopes(array $scopes): array
    {
        if (in_array('*', $scopes, true)) {
            return ['*'];
        }
        $scopes = array_values(array_unique(array_filter(array_map('strval', $scopes), static fn (string $scope): bool => preg_match('/^[a-z0-9*_.:-]+$/', $scope) === 1)));
        sort($scopes);
        return $scopes;
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
