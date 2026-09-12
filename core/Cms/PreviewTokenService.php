<?php

declare(strict_types=1);

namespace CajeerEngine\Cms;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Runtime\ConfigRepository;
use CajeerEngine\Support\JsonFile;
use CajeerEngine\Support\Uuid;
use PDO;
use Throwable;

final class PreviewTokenService
{
    private JsonFile $file;
    private ?DatabaseManager $database = null;
    private ?bool $databaseReady = null;

    public function __construct(private readonly string $rootPath, ?DatabaseManager $database = null)
    {
        $this->database = $database;
        $this->file = new JsonFile($this->rootPath . '/storage/app/cms/preview-tokens.json');
    }

    /** @return array<string,mixed> */
    public function create(string $type, string $entryId, int $ttlSeconds = 3600): array
    {
        $type = trim($type);
        $entryId = trim($entryId);
        if ($type === '' || $entryId === '') {
            throw new \InvalidArgumentException('type и entry_id обязательны для preview token.');
        }
        $ttlSeconds = max(60, min($ttlSeconds, 86400));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $expiresAt = (new \DateTimeImmutable())->modify('+' . $ttlSeconds . ' seconds')->format(DATE_ATOM);
        $record = [
            'id' => Uuid::v4(),
            'token_hash' => $hash,
            'type' => $type,
            'entry_id' => $entryId,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => $this->now(),
        ];

        if ($this->useDatabase()) {
            $stmt = $this->pdo()->prepare('INSERT INTO ce_cms_preview_tokens (id, token_hash, content_type, entry_id, expires_at, used_at, created_at) VALUES (:id, :token_hash, :content_type, :entry_id, :expires_at, :used_at, :created_at)');
            $stmt->execute([
                'id' => $record['id'],
                'token_hash' => $record['token_hash'],
                'content_type' => $record['type'],
                'entry_id' => $record['entry_id'],
                'expires_at' => $this->dbDate($expiresAt),
                'used_at' => null,
                'created_at' => $this->nowForDatabase(),
            ]);
        } else {
            $data = $this->file->readObject(['tokens' => []]);
            $tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
            $tokens[] = $record;
            $this->file->writeObject(['version' => 1, 'updated_at' => $this->now(), 'tokens' => $tokens]);
        }

        return [
            'token' => $token,
            'preview_url' => '/preview/' . $token,
            'type' => $type,
            'entry_id' => $entryId,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $token): ?array
    {
        $hash = hash('sha256', trim($token));
        $record = $this->useDatabase() ? $this->resolveDatabase($hash) : $this->resolveFile($hash);
        if ($record === null) {
            return null;
        }
        try {
            $expires = new \DateTimeImmutable((string) $record['expires_at']);
        } catch (\Throwable) {
            return null;
        }
        if ($expires < new \DateTimeImmutable()) {
            return null;
        }
        return $record;
    }

    /** @return array<string,mixed>|null */
    private function resolveDatabase(string $hash): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ce_cms_preview_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $this->pdo()->prepare('UPDATE ce_cms_preview_tokens SET used_at = :used_at WHERE id = :id')->execute(['id' => $row['id'], 'used_at' => $this->nowForDatabase()]);
        return [
            'id' => (string) $row['id'],
            'type' => (string) $row['content_type'],
            'entry_id' => (string) $row['entry_id'],
            'expires_at' => (string) $row['expires_at'],
            'used_at' => $row['used_at'] !== null ? (string) $row['used_at'] : null,
            'created_at' => (string) $row['created_at'],
            'storage' => 'database',
        ];
    }

    /** @return array<string,mixed>|null */
    private function resolveFile(string $hash): ?array
    {
        $data = $this->file->readObject(['tokens' => []]);
        $tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
        foreach ($tokens as $index => $record) {
            if (!is_array($record) || ($record['token_hash'] ?? null) !== $hash) {
                continue;
            }
            $tokens[$index]['used_at'] = $this->now();
            $this->file->writeObject(['version' => 1, 'updated_at' => $this->now(), 'tokens' => $tokens]);
            $record['storage'] = 'file';
            unset($record['token_hash']);
            return $record;
        }
        return null;
    }

    private function useDatabase(): bool
    {
        if ($this->databaseReady !== null) {
            return $this->databaseReady;
        }
        $mode = strtolower(trim((string) (getenv('CMS_STORAGE') ?: 'auto')));
        if ($mode === 'file') {
            return $this->databaseReady = false;
        }
        try {
            $this->databaseReady = $this->database()->tableExists('ce_cms_preview_tokens');
        } catch (Throwable $e) {
            if ($mode === 'database') {
                throw new \RuntimeException('CMS_STORAGE=database, но CMS preview table недоступна: ' . $e->getMessage(), 0, $e);
            }
            $this->databaseReady = false;
        }
        if ($mode === 'database' && $this->databaseReady !== true) {
            throw new \RuntimeException('CMS_STORAGE=database, но таблица ce_cms_preview_tokens не найдена. Выполните миграции.');
        }
        return $this->databaseReady;
    }

    private function database(): DatabaseManager
    {
        if (!$this->database instanceof DatabaseManager) {
            $this->database = new DatabaseManager(new ConfigRepository($this->rootPath));
        }
        return $this->database;
    }

    private function pdo(): PDO
    {
        return $this->database()->connection();
    }

    private function dbDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    private function nowForDatabase(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
