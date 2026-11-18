<?php

declare(strict_types=1);

namespace CajeerEngine\AI;

use CajeerEngine\Database\DatabaseManager;
use PDO;

/**
 * Bounded and opt-in removal of expired AI content. Closed support tickets only;
 * live tickets, token accounting and active chat generations are never purged.
 * Both the transcript and its parent are deleted in a single SQL transaction.
 */
final readonly class AiDataRetention
{
    public function __construct(private DatabaseManager $db) {}

    /** @return array<string,mixed> */
    public function run(int $days = 90, int $batch = 100, bool $apply = false, ?int $now = null): array
    {
        if ($days < 7 || $days > 3650 || $batch < 1 || $batch > 500)
            throw new \InvalidArgumentException('Retention requires --days=7..3650 and --batch=1..500.');
        $now ??= time();
        $cutoff = $now - $days * 86400;
        $plans = [
            'chat' => [
                'table' => 'ce_ai_conversations', 'condition' => 'updated_at < ? AND busy_until = 0 AND reserved_tokens = 0',
                'params' => [$cutoff], 'messages' => 'ce_ai_messages', 'foreign' => 'conversation_id',
            ],
            'knowledge_chat' => [
                'table' => 'ce_ai_kb_conversations', 'condition' => 'updated_at < ? AND NOT EXISTS (SELECT 1 FROM ce_ai_editor_jobs j WHERE j.user_id = ce_ai_kb_conversations.user_id AND j.expires_at >= ?)',
                'params' => [$cutoff, $now], 'messages' => 'ce_ai_kb_messages', 'foreign' => 'conversation_id',
            ],
            'closed_support' => [
                'table' => 'ce_support_tickets', 'condition' => 'status = ? AND updated_at < ?',
                'params' => ['closed', $cutoff], 'messages' => 'ce_support_messages', 'foreign' => 'ticket_id',
            ],
            // Expired unapproved proposals contain generated text, so prune them too.
            'editor_proposals' => [
                'table' => 'ce_ai_editor_proposals', 'condition' => 'expires_at < ?',
                'params' => [$now],
            ],
            'translation_proposals' => [
                'table' => 'ce_ai_translation_proposals', 'condition' => 'expires_at < ?',
                'params' => [$now],
            ],
            'action_proposals' => [
                'table' => 'ce_ai_actions', 'condition' => 'state <> ? AND expires_at < ?',
                'params' => ['running', $cutoff],
            ],
        ];
        $result = ['applied' => $apply, 'days' => $days, 'cutoff' => gmdate(DATE_ATOM, $cutoff), 'batch' => $batch, 'deleted' => [], 'more_possible' => false];
        foreach ($plans as $key => $plan) {
            $deleted = $this->db->transaction(function (PDO $pdo) use ($plan, $batch, $apply): int {
                // All identifiers and SQL fragments here are hard-coded by the application.
                $sql = 'SELECT id FROM '.$plan['table'].' WHERE '.$plan['condition'].' ORDER BY id ASC LIMIT ?';
                if ($apply && in_array($this->db->driver(), ['mysql', 'pgsql'], true)) $sql .= ' FOR UPDATE';
                $stmt = $pdo->prepare($sql);
                $i = 0;
                foreach ($plan['params'] as $parameter) $stmt->bindValue(++$i, $parameter);
                $stmt->bindValue(++$i, $batch, PDO::PARAM_INT);
                $stmt->execute();
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!$apply || $ids === []) return count($ids);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                if (isset($plan['messages'])) {
                    // Prevent FK-orphaned transcripts on installations with legacy FK settings.
                    $pdo->prepare('DELETE FROM '.$plan['messages'].' WHERE '.$plan['foreign'].' IN ('.$placeholders.')')->execute($ids);
                }
                $delete = $pdo->prepare('DELETE FROM '.$plan['table'].' WHERE id IN ('.$placeholders.') AND '.$plan['condition']);
                $delete->execute([...$ids, ...$plan['params']]);
                if ($delete->rowCount() !== count($ids)) {
                    throw new \RuntimeException('Retention selection changed during deletion; transaction rolled back.');
                }
                return count($ids);
            });
            $result['deleted'][$key] = $deleted;
            if ($deleted === $batch) $result['more_possible'] = true;
        }
        return $result;
    }
}
