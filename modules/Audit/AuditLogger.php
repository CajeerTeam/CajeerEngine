<?php

declare(strict_types=1);

namespace Cajeer\Modules\Audit;

use Cajeer\Database\DatabaseManager;
use Cajeer\Http\Request;
use Throwable;

final class AuditLogger
{
    public function __construct(private readonly DatabaseManager $database) {}

    /** @param array<string,mixed> $payload */
    public function record(string $event, ?int $actorId = null, ?string $targetType = null, string|int|null $targetId = null, array $payload = [], ?Request $request = null): void
    {
        try {
            $stmt = $this->database->connection()->prepare(
                'INSERT INTO cajeer_audit_log (actor_id, event, target_type, target_id, payload_json, ip_address, user_agent) VALUES (:actor_id, :event, :target_type, :target_id, :payload_json, :ip_address, :user_agent)'
            );
            $stmt->execute([
                'actor_id' => $actorId,
                'event' => $event,
                'target_type' => $targetType,
                'target_id' => $targetId === null ? null : (string) $targetId,
                'payload_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (Throwable) {
            // Audit must not break business flow during install or recovery.
        }
    }
}
