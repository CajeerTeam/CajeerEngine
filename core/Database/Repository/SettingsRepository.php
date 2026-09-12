<?php

declare(strict_types=1);

namespace CajeerEngine\Database\Repository;

use CajeerEngine\Database\DatabaseManager;
use CajeerEngine\Support\Uuid;

final readonly class SettingsRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stmt = $this->database->connection()->query('SELECT key, value, type, created_at, updated_at FROM ce_settings ORDER BY key ASC');
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['key']] = [
                'value' => $this->decode((string) $row['value']),
                'type' => (string) $row['type'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        return $result;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->database->connection()->prepare('SELECT value FROM ce_settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $this->decode((string) $value);
    }

    public function set(string $key, mixed $value, string $type = 'json', ?string $actorId = null): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/', $key)) {
            throw new \InvalidArgumentException('Некорректный ключ настройки.');
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $driver = $this->database->driver();
        $pdo = $this->database->connection();

        $this->database->transaction(function () use ($pdo, $driver, $key, $encoded, $type, $actorId): void {
            if ($driver === 'pgsql') {
                $sql = "INSERT INTO ce_settings (key, value, type, created_at, updated_at)
                        VALUES (:key, CAST(:value AS jsonb), :type, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, type = EXCLUDED.type, updated_at = CURRENT_TIMESTAMP";
            } elseif ($driver === 'sqlite') {
                $sql = "INSERT INTO ce_settings (key, value, type, created_at, updated_at)
                        VALUES (:key, :value, :type, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = CURRENT_TIMESTAMP";
            } else {
                $sql = "INSERT INTO ce_settings (`key`, value, type, created_at, updated_at)
                        VALUES (:key, :value, :type, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ON DUPLICATE KEY UPDATE value = VALUES(value), type = VALUES(type), updated_at = CURRENT_TIMESTAMP";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['key' => $key, 'value' => $encoded, 'type' => $type]);

            $historySql = $driver === 'pgsql'
                ? 'INSERT INTO ce_settings_history (id, key, value, actor_id, created_at) VALUES (:id, :key, CAST(:value AS jsonb), :actor_id, CURRENT_TIMESTAMP)'
                : 'INSERT INTO ce_settings_history (id, key, value, actor_id, created_at) VALUES (:id, :key, :value, :actor_id, CURRENT_TIMESTAMP)';
            $history = $pdo->prepare($historySql);
            $history->execute([
                'id' => Uuid::v4(),
                'key' => $key,
                'value' => $encoded,
                'actor_id' => $actorId,
            ]);
        });
    }

    private function decode(string $value): mixed
    {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
