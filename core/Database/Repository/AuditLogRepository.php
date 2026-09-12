<?php

declare(strict_types=1);

namespace CajeerEngine\Database\Repository;

use CajeerEngine\Database\DatabaseManager;

final readonly class AuditLogRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /** @param array<string, mixed> $context */
    public function record(string $event, ?string $actorId = null, array $context = [], ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $driver = $this->database->driver();
        $sql = $driver === 'pgsql'
            ? 'INSERT INTO ce_audit_log (event, actor_id, context, ip_address, user_agent, created_at) VALUES (:event, :actor_id, CAST(:context AS jsonb), :ip_address, :user_agent, CURRENT_TIMESTAMP)'
            : 'INSERT INTO ce_audit_log (event, actor_id, context, ip_address, user_agent, created_at) VALUES (:event, :actor_id, :context, :ip_address, :user_agent, CURRENT_TIMESTAMP)';
        $stmt = $this->database->connection()->prepare($sql);
        $stmt->execute([
            'event' => $event,
            'actor_id' => $actorId,
            'context' => $json,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    public function count(): int
    {
        return (int) $this->database->connection()->query('SELECT COUNT(*) FROM ce_audit_log')->fetchColumn();
    }
}
